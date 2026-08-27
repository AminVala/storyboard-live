<?php
declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Vision;

use ShahreHonar\SequenceEngine\SequenceWizard\Infrastructure\Persistence\WizardStateRepository;

/**
 * OpenAI Vision API برای استخراج متن + موقعیت از Golden Master PNG
 */
final class OpenAIVisionExtractor implements VisionExtractorInterface
{
    private const AS_HOOK = 'shseq_vision_extract';

    public function __construct(
        private readonly string                $apiKey,
        private readonly WizardStateRepository $repo,
    ) {}

    public function scheduleExtraction(int $sequencePostId, int $attachmentId): void
    {
        if (! function_exists('as_schedule_single_action')) {
            // Action Scheduler نصب نیست — همزمان اجرا می‌کنیم
            $this->runJob($sequencePostId, $attachmentId);
            return;
        }

        as_schedule_single_action(
            time(),
            self::AS_HOOK,
            ['sequence_post_id' => $sequencePostId, 'attachment_id' => $attachmentId],
            'shseq',
        );
    }

    /** callback برای Action Scheduler — باید در Plugin.php register شود */
    public function runJob(int $sequencePostId, int $attachmentId): void
    {
        try {
            $detections = $this->extractTexts($attachmentId);

            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->applyTextExtractionResult($detections);
            $this->repo->save($state);

            // بلافاصله inpainting را هم زمان‌بندی کن
            (new GDInpainter($this->repo))->scheduleInpainting($sequencePostId, $attachmentId, $detections);

        } catch (\Throwable $e) {
            $state = $this->repo->findBySequenceId($sequencePostId);
            $state->fail('Vision extraction failed: ' . $e->getMessage());
            $this->repo->save($state);
        }
    }

    public function extractTexts(int $attachmentId): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key is not configured');
        }

        $imagePath = get_attached_file($attachmentId);
        if (! $imagePath || ! file_exists($imagePath)) {
            throw new \RuntimeException("Attachment file not found for ID {$attachmentId}");
        }

        // تصویر را به base64 تبدیل می‌کنیم
        $mimeType  = mime_content_type($imagePath) ?: 'image/png';
        $imageData = base64_encode(file_get_contents($imagePath));
        $imageUrl  = "data:{$mimeType};base64,{$imageData}";

        $prompt = <<<'PROMPT'
You are a precise text extraction assistant.
Analyze this image and return ALL visible text elements.
For each text element, return:
- "text": the exact text content
- "x_rel": left edge as fraction of image width (0.0–1.0)
- "y_rel": top edge as fraction of image height (0.0–1.0)
- "width_rel": element width as fraction of image width (0.0–1.0)
- "height_rel": element height as fraction of image height (0.0–1.0)

Return ONLY a valid JSON array, no markdown, no explanation.
Example: [{"text":"Hello","x_rel":0.1,"y_rel":0.05,"width_rel":0.3,"height_rel":0.08}]
PROMPT;

        $body = wp_json_encode([
            'model'      => 'gpt-4o',
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
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
            throw new \RuntimeException('OpenAI API error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new \RuntimeException("OpenAI API returned HTTP {$code}");
        }

        $data    = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        // پاک کردن markdown wrappers اگر وجود داشت
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $detections = json_decode($content, true);
        if (! is_array($detections)) {
            throw new \RuntimeException('OpenAI returned invalid JSON: ' . $content);
        }

        return $detections;
    }

    /** register کردن Action Scheduler hook */
    public static function registerHooks(self $instance): void
    {
        add_action(self::AS_HOOK, [$instance, 'runJob'], 10, 2);
    }
}
