<?php
/**
 * CommandBus — اتصال Command به Handler بدون کتابخانه خارجی
 *
 * @package ShahreHonar\SequenceEngine\SequenceWizard\Application
 */

declare(strict_types=1);

namespace ShahreHonar\SequenceEngine\SequenceWizard\Application;

/**
 * ساده‌ترین CommandBus ممکن:
 * از نام کلاس Command به Handler مناسب route می‌کند.
 *
 * تمام Handler‌ها باید متد handle($command): void داشته باشند.
 */
final class CommandBus
{
    /** @var array<class-string, object> */
    private array $handlers = [];

    /**
     * ثبت Handler برای یک Command
     *
     * @param class-string $commandClass
     */
    public function register(string $commandClass, object $handler): void
    {
        if (! method_exists($handler, 'handle')) {
            throw new \InvalidArgumentException(
                sprintf("Handler for '%s' must have a handle() method", $commandClass),
            );
        }
        $this->handlers[$commandClass] = $handler;
    }

    /**
     * اجرای Command — Handler را پیدا می‌کند و فراخوانی می‌کند
     *
     * @throws \RuntimeException اگر Handler پیدا نشود
     */
    public function dispatch(object $command): mixed
    {
        $class = get_class($command);

        if (! isset($this->handlers[$class])) {
            throw new \RuntimeException(
                sprintf("No handler registered for command '%s'", $class),
            );
        }

        return $this->handlers[$class]->handle($command);
    }
}
