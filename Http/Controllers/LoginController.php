<?php


namespace Modules\Oauth2\Http\Controllers;

use App\Http\Controllers\Auth\LoginController as AppLoginController;
use App\User;
use App\Role;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Modules\Oauth2\Entities\OauthProvider;
use Modules\Oauth2\Entities\OauthProviderClient;
use Modules\Oauth2\Entities\SocialAccount;


class LoginController extends AppLoginController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Перенаправить запрос на провайдера
     * @param $provider_client_id
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider($provider_client_id)
    {
        $providerClient = OauthProviderClient::find($provider_client_id);
        if (!$providerClient)
            return redirect()->back()->withErrors(['message' => 'Provider client not found.']);
        $this->setClientConfig($providerClient);
        session()->put('provider_client_id', $provider_client_id);
        return Socialite::driver($providerClient->provider->name)->stateless()->redirect();
    }

    /**
     * Обработать обратный запрос провайдера на получение пользователя, зарегистрировать,
     * если не существует и залогинить его в системе
     * @param $provider
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function handleProviderCallback($provider)
    {
        $providerClient = OauthProviderClient::find(session()->get('provider_client_id'));
        $this->setClientConfig($providerClient);
        $provider = OauthProvider::where('name', $provider)->first();
        if (!$provider)
            return redirect()->back()->withErrors(['message' => 'Provider not found.']);
        try {
            $socialiteUser = Socialite::driver($provider->name)->stateless()->user();
            $user = $this->findOrCreateUser($provider, $providerClient, $socialiteUser);
            if (!$user)
                return redirect()->back();
            auth()->login($user, true);
        } catch (\Exception $e) {
            return redirect('/login/' . session()->get('provider_client_id'));
        }
        return redirect($this->redirectTo);
    }

    /**
     * Записать в файл конфигурации сервиса настройки для провайдера
     * @param $client
     * @param string $file
     * @return false|int
     */
    public function writeProviderClientSettingsToConfig($client, string $file)
    {
        $tab = '    ';
        $fileContent = file($file, 2);
        foreach ($fileContent as $line => $content) {
            if (Str::contains($content, $client->provider->name)) {
                $settings = [
                    $tab . $tab ."'client_id' => '" . $client->client_id . "',",
                    $tab . $tab ."'client_secret' => '" . $client->client_secret . "',",
                    $tab . $tab ."'redirect' => '" . $client->provider->redirect_uri . "',",
                ];
                if ($client->host) $settings[] = $tab . $tab ."'host' => '" . $client->host . "',";
                for ($i = $line + 1, $s = 0; $s < count($settings); $i++, $s++) {
                    array_splice($fileContent, $i, 1, $settings[$s]);;
                }
                break;
            }
        }
        return file_put_contents($file, implode(PHP_EOL, $fileContent));
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

        $user = User::create([
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
        $roles = collect($socialiteUser->getRaw()['oauth_roles'])
            ->where('oauth_client_id', $providerClient->client_id)
            ->where('passport_id', $providerClient->id)
            ->pluck('display_name')
            ->all();
        $roles = Role::whereIn('name', $roles)->get();
        $user->detachRoles($roles);
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
        $socialAccount = SocialAccount::where('provider_id', $provider->id)
            ->where('oauth_uid', $id)
            ->first();
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
        return !$email ? null : User::where('email', $email)->first();
    }

    /**
     * Добавить новый аккаунт в бд
     * @param $provider
     * @param $user
     * @param $socialiteUser
     */
    public function addSocialAccount($provider, $user, $socialiteUser)
    {
        SocialAccount::create([
            'user_id' => $user->id,
            'oauth_uid' => $socialiteUser->getId(),
            'provider_id' => $provider->id,
            'token' => $socialiteUser->token,
        ]);
    }

    /**
     * Установить конфигурацию клиента провайдера
     * @param $providerClient
     */
    protected function setClientConfig($providerClient)
    {
        $config = [
            'client_id' => $providerClient->client_id,
            'client_secret' => $providerClient->client_secret,
            'redirect' => $providerClient->provider->redirect_uri,
        ];
        if ($providerClient->host) {
            $config['host'] = $providerClient->host;
        }
        Config::set('services.' . $providerClient->provider->name, $config);
    }
}
