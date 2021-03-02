<?php


namespace Modules\Oauth2\Services;


use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ixudra\Curl\Facades\Curl;
use Modules\Oauth2\Services\Repositories\OauthProviderClient\OauthProviderClientRepositoryInterface;
use Modules\Oauth2\Services\Repositories\SocialAccount\SocialAccountRepositoryInterface;
use Modules\Oauth2\Services\Repositories\User\UserRepositoryInterface;

class SocialAccountsService
{
    /**
     * @var UserRepositoryInterface
     */
    protected $userRepo;

    /**
     * @var OauthProviderClientRepositoryInterface
     */
    protected $oauthProviderClientRepo;

    /**
     * @var SocialAccountRepositoryInterface
     */
    protected $socialAccountRepo;

    /**
     * SocialAccountsService constructor.
     * @param UserRepositoryInterface $userRepo
     * @param OauthProviderClientRepositoryInterface $oauthProviderClientRepo
     * @param SocialAccountRepositoryInterface $socialAccountRepo
     */
    public function __construct(
        UserRepositoryInterface $userRepo,
        OauthProviderClientRepositoryInterface $oauthProviderClientRepo,
        SocialAccountRepositoryInterface $socialAccountRepo
    ) {
        $this->userRepo = $userRepo;
        $this->oauthProviderClientRepo = $oauthProviderClientRepo;
        $this->socialAccountRepo = $socialAccountRepo;
    }

    /**
     * Получить или создать нового пользователя
     * @param $provider
     * @param $providerClient
     * @param $socialiteUser
     * @return User
     */
    public function findOrCreateUser($provider, $providerClient, $socialiteUser)
    {
        if ($user = $this->findUserBySocialId($provider, $socialiteUser->getId())) {
            $user = $this->attachRoles($user, $providerClient, $socialiteUser);
            return $user;
        }

        if ($user = $this->findUserByEmail($provider, $socialiteUser->getEmail())) {
            $user = $this->attachRoles($user, $providerClient, $socialiteUser);
            $this->addSocialAccount($provider, $user, $socialiteUser);
            return $user;
        }

        $user = $this->userRepo->createFromArray([
            'name' => $socialiteUser->getName(),
            'email' => $socialiteUser->getEmail(),
            'password' => Hash::make(Str::random(24)),
        ]);

        if ($providerClient->role_id) {
            $user = $this->attachRoles($user, $providerClient, $socialiteUser);
        }

        $this->addSocialAccount($provider, $user, $socialiteUser);

        return $user;
    }

    /**
     * Прикрепить роли
     * @param $user
     * @param $providerClient
     * @param $socialiteUser
     * @return mixed
     */
    protected function attachRoles($user, $providerClient, $socialiteUser)
    {
        $providers = collect($socialiteUser->getRaw()['oauth_roles'])
            ->where('oauth_client_id', $providerClient->client_id)
            ->pluck('passport_id')
            ->all();
        $roles = [];
        $providerClients = $this->oauthProviderClientRepo->getByIds($providers);
        foreach ($providerClients as $client) {
            $roles[] = $client->role_id;
        }
        $user->detachRoles($user->roles);
        $user->attachRoles($roles);

        return $user;
    }

    /**
     * Получить пользователя по идентификатору в клиентском приложении
     * @param $provider
     * @param $id
     * @return User|bool
     */
    public function findUserBySocialId($provider, $id)
    {
        $socialAccount = $this->socialAccountRepo->findUserBySocialId($provider, $id);
        return $socialAccount ? $socialAccount->user : false;
    }

    /**
     * Получить пользователя по электронной почте
     * @param $provider
     * @param $email
     * @return User|null
     */
    public function findUserByEmail($provider, $email)
    {
        return !$email ? null : $this->userRepo->findByEmail($email);
    }

    /**
     * Добавить новый аккаунт в бд
     * @param $provider
     * @param $user
     * @param $socialiteUser
     * @return mixed
     */
    public function addSocialAccount($provider, $user, $socialiteUser)
    {
        return $this->socialAccountRepo->createFromArray([
            'user_id' => $user->id,
            'oauth_uid' => $socialiteUser->getId(),
            'provider_id' => $provider->id,
            'token' => $socialiteUser->token,
        ]);
    }

    /**
     * Отправить запрос для разрешения и авторизации в чате
     * @param $login
     * @return array|mixed|\stdClass
     */
    public function sendCurlToChatAuth($login)
    {
        $project = str_replace(['http://', 'https://'], '', config('app.url'));
        return Curl::to('https://event.regagro.net/script/user.php?token=aedb78a15672fcb7d96f4f8d2be17337&action=addusertoroom&project='
            . $project . '&username=' . $login)
            ->asJsonResponse(true)
            ->get();
    }
}
