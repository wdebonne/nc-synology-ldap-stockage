<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\AppInfo;

use OCA\SynoLDAP\Listener\UserLoggedInListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\PostLoginEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'synoldap';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(PostLoginEvent::class, UserLoggedInListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
