<?php
/**
 * OpenAIVisionService — استخراج متن + موقعیت از Last Frame
 *
 * این سرویس در Action Scheduler (async) اجرا می‌شود.
 * پس از استخراج، بلافاصله GD Inpainter را هم schedule می‌کند.
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Inpainting\GDInpaintingService;
use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardRepository;

final class OpenAIVisionService
{
    private const AS_HOOK = 'shseq_vision_extract';

    public function __construct(
        private readonly string            $apiKey,
        private readonly WizardRepository  $repo,
        private readonly GDInpaintingService $inpainter,
    ) {}

    /**
     * Schedule async extraction
     * اگر API Key نداشت → fallback: overlay خالی، canvas فوری
     */
    public function scheduleExtraction(int $sequencePostId, int $attachmentId): void
    {
        if (empty($this->apiKey)) {
            // Fallback: بدون Vision → canvas خالی نمایش داده می‌شود
            $this->applyEmptyDetections($sequencePostId, $attachmentId);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                self::AS_HOOK,
                ['post_id' => $sequencePostId, 'attachment_id' => $attachmentId],
                'shseq',
            );
        } else {
            $this->runExtraction($sequencePostId, $attachmentId);
        }
    }

    /** Action Scheduler callback */
    public function runExtraction(int $postId, int $attachmentId): void
    {
        try {
            $detections = $this->extractFromImage($attachmentId);

            $agg = $this->repo->find($postId);
            $agg->applyVisionDetections($detections);
            $this->repo->save($agg);

            // بلافاصله inpainting را schedule کن
            $this->inpainter->scheduleInpainting($postId, $attachmentId, $detections);

        } catch (\Throwable $e) {
            // Vision خطا داشت → canvas خالی نمایش بده (کاربر خودش overlay اضافه می‌کند)
            $this->applyEmptyDetections($postId, $attachmentId);
            error_log('[shseq] Vision extraction failed: ' . $e->getMessage());
        }
    }

    /** وقتی Vision موجود نیست یا خطا داد */
    private function applyEmptyDetections(int $postId, int $attachmentId): void
    {
        try {
            $agg = $this->repo->find($postId);
            $agg->applyVisionDetections([]); // canvas خالی
            $this->repo->save($agg);
            // inpainting با mask خالی = کپی ساده
            $this->inpainter->scheduleInpainting($postId, $attachmentId, []);
        } catch (\Throwable $e) {
            error_log('[shseq] applyEmptyDetections failed: ' . $e->getMessage());
        }
    }

    /**
     * فراخوانی OpenAI Vision API
     *
     * @return list<array{text:string, x_rel:float, y_rel:float, width_rel:float, height_rel:float}>
     */
    public function extractFromImage(int $attachmentId): array
    {
        $imagePath = get_attached_file($attachmentId);
        if (! $imagePath || ! file_exists($imagePath)) {
            throw new \RuntimeException("Attachment file not found: {$attachmentId}");
        }

        $mime      = mime_content_type($imagePath) ?: 'image/png';
        $imageData = base64_encode(file_get_contents($imagePath));
        $imageUrl  = "data:{$mime};base64,{$imageData}";

        $systemPrompt = <<<'PROMPT'
You are a precise OCR and layout analysis assistant.
Analyze the image and detect ALL visible text elements.
For each text element return:
- "text": exact text content (preserve Persian/Arabic characters exactly)
- "x_rel": left edge / image width (0.0–1.0)
- "y_rel": top edge / image height (0.0–1.0)
- "width_rel": text box width / image width (0.0–1.0)
- "height_rel": text box height / image height (0.0–1.0)

Rules:
- Group closely-related words into one block if they're part of the same heading/line
- Separate distinct text elements (headings, body, captions)
- Preserve RTL text (Persian/Arabic) correctly
- Return ONLY a JSON array, no markdown, no explanation

Example: [{"text":"سلام","x_rel":0.1,"y_rel":0.05,"width_rel":0.2,"height_rel":0.07}]
PROMPT;

        $body = wp_json_encode([
            'model'      => 'gpt-4o',
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $systemPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'high']],
                ],
            ]],
        ]);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('OpenAI request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new \RuntimeException("OpenAI API returned HTTP {$code}");
        }

        $data    = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        // strip markdown
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $detections = json_decode($content, true);
        if (! is_array($detections)) {
            throw new \RuntimeException('Vision API returned invalid JSON');
        }

        // sanitize و clamp
        return array_map(function (array $d): array {
            return [
                'text'      => (string)($d['text']      ?? ''),
                'x_rel'     => max(0.0, min(1.0, (float)($d['x_rel']      ?? 0.0))),
                'y_rel'     => max(0.0, min(1.0, (float)($d['y_rel']      ?? 0.0))),
                'width_rel' => max(0.01, min(1.0, (float)($d['width_rel']  ?? 0.2))),
                'height_rel'=> max(0.01, min(1.0, (float)($d['height_rel'] ?? 0.08))),
            ];
        }, $detections);
    }

    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runExtraction'], 10, 2);
    }
}
