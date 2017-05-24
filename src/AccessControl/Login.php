<?php

namespace Bolt\AccessControl;

use Bolt\AccessControl\Token\Token;
use Bolt\Events\AccessControlEvent;
use Bolt\Events\AccessControlEvents;
use Bolt\Exception\AccessControlException;
use Bolt\Exception\PasswordHashException;
use Bolt\Exception\PasswordLegacyHashException;
use Bolt\Storage\Entity;
use Bolt\Translation\Translator as Trans;
use Carbon\Carbon;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Silex\Application;

/**
 * Login authentication handling.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Login extends AccessChecker
{
    /** @var PasswordHashManager */
    protected $passwordFactory;
    /** @var string */
    protected $authTokenName;

    /**
     * Constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        parent::__construct(
            $app['storage.lazy'],
            $app['request_stack'],
            $app['session'],
            $app['dispatcher'],
            $app['logger.flash'],
            $app['logger.system'],
            $app['permissions'],
            $app['randomgenerator'],
            $app['access_control.cookie.options']
        );
        $this->passwordFactory = $app['password_hash.manager'];
        $this->authTokenName = $app['token.authentication.name'];
    }

    /**
     * Attempt to login a user with the given password. Accepts username or
     * email.
     *
     * @param string             $userName
     * @param string             $password
     * @param AccessControlEvent $event
     *
     * @throws AccessControlException
     *
     * @return bool
     */
    public function login($userName, $password, AccessControlEvent $event)
    {
        $authCookie = $this->requestStack->getCurrentRequest()->cookies->get($this->authTokenName);

        // Remove expired tokens
        $this->getRepositoryAuthtoken()->deleteExpiredTokens();

        if ($userName !== null && $password !== null) {
            return $this->loginCheckPassword($userName, $password, $event);
        } elseif ($authCookie !== null) {
            return $this->loginCheckAuthtoken($authCookie, $event);
        }

        $this->systemLogger->error('Login function called with empty username/password combination, or no authentication token.', ['event' => 'security']);
        throw new AccessControlException('Invalid login parameters.');
    }

    /**
     * Check a user login request for username/password combinations.
     *
     * @param string             $userName
     * @param string             $password
     * @param AccessControlEvent $event
     *
     * @return bool
     */
    protected function loginCheckPassword($userName, $password, AccessControlEvent $event)
    {
        if (!$userEntity = $this->getUserEntity($userName)) {
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_INVALID));

            return false;
        }

        $userAuth = $this->getRepositoryUsers()->getUserAuthData($userEntity->getId());
        if ($userAuth->getPassword() === null || $userAuth->getPassword() === '') {
            $this->systemLogger->alert("Attempt to login to an account with empty password field: '$userName'", ['event' => 'security']);
            $this->flashLogger->error(Trans::__('general.phrase.login-account-disabled'));
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_DISABLED));

            return $this->loginFailed($userEntity);
        }

        if ((bool) $userEntity->getEnabled() === false) {
            $this->systemLogger->alert("Attempt to login to a disabled account: '$userName'", ['event' => 'security']);
            $this->flashLogger->error(Trans::__('general.phrase.login-account-disabled'));
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_DISABLED));

            return $this->loginFailed($userEntity);
        }

        try {
            $isValid = $this->passwordFactory->verifyHash($password, $userAuth->getPassword());
        } catch (PasswordLegacyHashException $e) {
            $this->flashLogger->error(Trans::__('general.phrase.login-password-legacy'));

            return false;
        } catch (PasswordHashException $e) {
            $this->flashLogger->error(Trans::__('general.phrase.login-password-hash-failure'));

            return false;
        }

        if (!$isValid) {
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_PASSWORD));

            return $this->loginFailed($userEntity);
        }

        $this->dispatcher->dispatch(AccessControlEvents::LOGIN_SUCCESS, $event->setDispatched());

        return $this->loginFinish($userEntity);
    }

    /**
     * Attempt to login a user via the bolt_authtoken cookie.
     *
     * @param string             $authCookie
     * @param AccessControlEvent $event
     *
     * @return bool
     */
    protected function loginCheckAuthtoken($authCookie, AccessControlEvent $event)
    {
        if (!$userTokenEntity = $this->getRepositoryAuthtoken()->getToken($authCookie, $this->getClientIp(), $this->getClientUserAgent())) {
            $this->flashLogger->error(Trans::__('general.phrase.error-login-invalid-parameters'));
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_INVALID));

            return false;
        }

        $checkSalt = $this->getAuthToken($userTokenEntity->getUserId(), $userTokenEntity->getSalt());
        if ($checkSalt === $userTokenEntity->getToken()) {
            if (!$userEntity = $this->getUserEntity($userTokenEntity->getUserId())) {
                $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_INVALID));

                return false;
            }

            $cookieLifetime = (integer) $this->cookieOptions['lifetime'];
            $userTokenEntity->setValidity(Carbon::create()->addSeconds($cookieLifetime));
            $userTokenEntity->setLastseen(Carbon::now());
            $this->getRepositoryAuthtoken()->save($userTokenEntity);
            $this->flashLogger->success(Trans::__('general.phrase.session-resumed-colon'));
            $this->dispatcher->dispatch(AccessControlEvents::LOGIN_SUCCESS, $event->setDispatched());

            return $this->loginFinish($userEntity);
        }

        $this->dispatcher->dispatch(AccessControlEvents::LOGIN_FAILURE, $event->setReason(AccessControlEvents::FAILURE_INVALID));
        $this->systemLogger->alert(sprintf('Attempt to login with an invalid token from %s', $this->getClientIp()), ['event' => 'security']);

        return false;
    }

    /**
     * Get the user record entity if it exists.
     *
     * @param string $userName
     *
     * @return Entity\Users|null
     */
    protected function getUserEntity($userName)
    {
        if (!$userEntity = $this->getRepositoryUsers()->getUser($userName)) {
            $this->flashLogger->error(Trans::__('general.phrase.error-user-name-password-incorrect'));

            return null;
        }

        if (!$userEntity->getEnabled()) {
            $this->systemLogger->alert("Attempt to login with disabled account by '$userName'", ['event' => 'security']);
            $this->flashLogger->error(Trans::__('general.phrase.login-account-disabled'));

            return null;
        }

        return $userEntity;
    }

    /**
     * Finish user login process(es).
     *
     * @param Entity\Users $userEntity
     *
     * @return boolean
     */
    protected function loginFinish(Entity\Users $userEntity)
    {
        if (!$this->updateUserLogin($userEntity)) {
            return false;
        }

        $tokenEntity = $this->updateAuthToken($userEntity);
        $token = new Token($userEntity, $tokenEntity);

        $this->session->set('authentication', $token);
        $this->session->save();

        return true;
    }

    /**
     * Add error messages to logs and update the user.
     *
     * @param Entity\Users $userEntity
     *
     * @return false
     */
    protected function loginFailed(Entity\Users $userEntity)
    {
        $this->flashLogger->error(Trans::__('general.phrase.error-user-name-password-incorrect'));
        $this->systemLogger->info("Failed login attempt for '" . $userEntity->getDisplayname() . "'.", ['event' => 'authentication']);

        // Update the failed login attempts, and perhaps throttle the logins.
        $userEntity->setFailedlogins($userEntity->getFailedlogins() + 1);
        $userEntity->setThrottleduntil($this->throttleUntil($userEntity->getFailedlogins() + 1));
        $userEntity->setPassword(null);
        $this->getRepositoryUsers()->save($userEntity);

        return false;
    }

    /**
     * Update the user record with latest login information.
     *
     * @param Entity\Users $userEntity
     *
     * @return boolean
     */
    protected function updateUserLogin(Entity\Users $userEntity)
    {
        $userEntity->setLastseen(Carbon::now());
        $userEntity->setLastip($this->getClientIp());
        $userEntity->setFailedlogins(0);
        $userEntity->setThrottleduntil($this->throttleUntil(0));
        $userEntity = $this->updateUserShadowLogin($userEntity);

        // Don't try to save the password on login
        $userEntity->setPassword(null);
        try {
            $saved = $this->getRepositoryUsers()->save($userEntity);
        } catch (NotNullConstraintViolationException $e) {
            // Database needs updating
            $saved = true;
        }
        if ($saved) {
            $this->flashLogger->success(Trans::__("You've been logged on successfully."));

            return true;
        }

        return false;
    }

    /**
     * Remove expired shadow login data.
     *
     * @param Entity\Users $userEntity
     *
     * @return Entity\Users
     */
    protected function updateUserShadowLogin(Entity\Users $userEntity)
    {
        if (Carbon::now() > $userEntity->getShadowvalidity()) {
            $userEntity->setShadowpassword(null);
            $userEntity->setShadowtoken(null);
            $userEntity->setShadowvalidity(null);
        }

        return $userEntity;
    }

    /**
     * Set the Authtoken cookie and DB-entry. If it's already present, update it.
     *
     * @param Entity\Users $userEntity
     *
     * @return Entity\Authtoken
     */
    protected function updateAuthToken(Entity\Users $userEntity)
    {
        $userName = $userEntity->getUsername();
        $cookieLifetime = (integer) $this->cookieOptions['lifetime'];
        $repo = $this->getRepositoryAuthtoken();
        $tokenEntity = $repo->getUserToken($userEntity->getId(), $this->getClientIp(), $this->getClientUserAgent());

        if ($tokenEntity) {
            $token = $tokenEntity->getToken();
        } else {
            $salt = $this->randomGenerator->generateString(32);
            $token = $this->getAuthToken($userEntity->getId(), $salt);

            $tokenEntity = new Entity\Authtoken();
            $tokenEntity->setUserId($userEntity->getId());
            $tokenEntity->setToken($token);
            $tokenEntity->setSalt($salt);
        }

        $tokenEntity->setValidity(Carbon::create()->addSeconds($cookieLifetime));
        $tokenEntity->setIp($this->getClientIp());
        $tokenEntity->setLastseen(Carbon::now());
        $tokenEntity->setUseragent($this->getClientUserAgent());
        $this->getRepositoryAuthtoken()->save($tokenEntity);

        $this->systemLogger->debug("Saving new login token '$token' for user ID '$userName'", ['event' => 'authentication']);

        return $tokenEntity;
    }

    /**
     * Calculate the amount of time until we should throttle login attempts for
     * a user.
     *
     * The amount is increased exponentially with each attempt: 1, 4, 9, 16, 25,
     * 36… seconds.
     *
     * Note: I just realized this is conceptually wrong: we should throttle
     * based on remote_addr, not username. So, this isn't used, yet.
     *
     * @param integer $attempts
     *
     * @return \DateTime
     */
    private function throttleUntil($attempts)
    {
        if ($attempts < 5) {
            return null;
        }
        $wait = pow(($attempts - 4), 2);

        return Carbon::create()->addSeconds($wait);
    }
}
