<?php


namespace Modules\Oauth2\Http\Controllers;

use App\Http\Controllers\Auth\LoginController as AppLoginController;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ixudra\Curl\Facades\Curl;
use Laravel\Socialite\Facades\Socialite;
use Modules\Oauth2\Entities\OauthLoginAction;
use Modules\Oauth2\Entities\OauthProvider;
use Modules\Oauth2\Entities\OauthProviderClient;
use Modules\Oauth2\Entities\SocialAccount;
use SocialiteProviders\Manager\OAuth2\User as OauthUser;


class LoginController extends AppLoginController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Перенаправить запрос на провайдера
     * @param Request $request
     * @param $provider_client_id
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider(Request $request, $provider_client_id)
    {
        $mobileLogin = $request->has('mobile') && $request->get('mobile') == 1 ? 1 : 0;
        $providerClient = OauthProviderClient::find($provider_client_id);
        if (!$providerClient)
            return redirect()->back()->withErrors(['message' => 'Provider client not found.']);
        $this->setClientConfig($providerClient);
        session()->put('provider_client_id', $provider_client_id);
        session()->put('is_mobile_login', $mobileLogin);
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
        $isMobile = session()->get('is_mobile_login');
        $this->setClientConfig($providerClient);
        $provider = OauthProvider::where('name', $provider)->first();
        if (!$provider)
            return redirect()->back()->withErrors(['message' => 'Provider not found.']);
        try {
            $socialiteUser = Socialite::driver($provider->name)->stateless()->user();
            if ($isMobile) {
                session()->forget('is_mobile_login');
                return $socialiteUser->accessTokenResponseBody;
            }
            $user = $this->findOrCreateUser($provider, $providerClient, $socialiteUser);
            if (!$user)
                return redirect()->back();
            auth()->login($user, true);
            $this->doAfterLoginActions($providerClient, $socialiteUser, $user);
            $this->authInTheChat($user);
        } catch (\Exception $e) {
            return redirect('/login/' . session()->get('provider_client_id'));
        }
        return redirect($this->redirectTo);
    }

    /**
     * Выполнить действия после авторизации
     * @param $providerClient
     * @param $socialiteUser
     * @param $user
     */
    protected function doAfterLoginActions($providerClient, $socialiteUser, $user)
    {
        $actions = OauthLoginAction::where([['provider_client_id', $providerClient->id]])->get();
        if ($actions) {
            foreach ($actions as $action) {
                $model = '\\' . $action->model_class;
                $modelObj = new $model();
                $data = $this->getModelFillableData($socialiteUser, $user, $action);
                foreach ($data as $attr => $val) {
                    $modelObj->$attr = $val;
                }
                $modelObj->save();
            }
        }
    }

    /**
     * Сформировать данные для заполнения атрибутов модели
     * @param $source
     * @param $user
     * @param $action
     * @return array
     */
    protected function getModelFillableData($source, $user, $action)
    {
        $fillableData = [];
        foreach ($action->data as $key => $value) {
            $fillableData[$value] = $this->parseSource($key, $source, $user);
        }
        return $fillableData;
    }

    /**
     * Обработать данные источника данных
     * @param $key
     * @param $source
     * @param $user
     * @return array|mixed
     */
    protected function parseSource($key, $source, $user)
    {
        if ($source instanceof OauthUser) {
            $value = $source->getRaw();
        } else {
            $value = $source;
        }
        $value['current_user'] = $user->toArray();
        $keyParts = explode('.', $key);
        foreach ($keyParts as $part) {
            if (is_object($part))
                $value = $value->$part;
            else
                $value = $value[$part];
        }
        return $value;
    }

    /**
     * Авторизовать пользователя в чате
     * @param $user
     */
    protected function authInTheChat($user)
    {
        $emailParts = explode('@', $user->email);
        $login = implode('_', $emailParts);
        $userFullName = $user->name;
        $userFullName .= $user->last_name ? ' ' . $user->last_name : '';

        $data = json_decode('{"identifier": {
            "type": "m.id.user",
            "user": "' . $login . '"
          },
          "initial_device_display_name": "' . $userFullName . '",
          "password": "globus",
          "type": "m.login.password"}');

        $response = Curl::to('https://event.regagro.net/_matrix/client/r0/login')
            ->withData($data)
            ->asJson()
            ->post();

        if (isset($response->error)) {
            Log::info($response->error);
        }
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
        $providers = collect($socialiteUser->getRaw()['oauth_roles'])
            ->where('oauth_client_id', $providerClient->client_id)
            ->pluck('passport_id')
            ->all();
        $roles = [];
        $providerClients = OauthProviderClient::whereIn('id', $providers)->get();
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
