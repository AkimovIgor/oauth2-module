<?php


namespace Modules\Oauth2\Http\Controllers;

use App\Http\Controllers\Auth\LoginController as AppLoginController;

use App\Services\Robots\RobotsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Modules\Oauth2\Entities\OauthLoginAction;
use Modules\Oauth2\Entities\OauthProvider;
use Modules\Oauth2\Entities\OauthProviderClient;
use Modules\Oauth2\Services\SocialAccountsService;
use SocialiteProviders\Manager\OAuth2\User as OauthUser;
use Illuminate\Support\Facades\Auth;


class LoginController extends AppLoginController
{
    /**
     * @var SocialAccountsService
     */
    protected $socialAccountsService;

    /**
     * LoginController constructor.
     * @param SocialAccountsService $socialAccountsService
     * @param RobotsService $robotsService
     */
    public function __construct(
        SocialAccountsService $socialAccountsService,
        RobotsService $robotsService
    ) {
        parent::__construct($robotsService);
        $this->socialAccountsService = $socialAccountsService;
        Auth::logout();
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
            $user = $this->socialAccountsService->findOrCreateUser($provider, $providerClient, $socialiteUser);
            if (!$user)
                return redirect()->back();

            $emailParts = explode('@', $user->email);
            $login = implode('_', $emailParts);

            setcookie("chat_login_username']", $login, [
                'expires' => time() + 3600,
                'path' => '/',
                'domain' => 'regagro.net',
            ]);

            $providerClients = collect($socialiteUser->getRaw()['oauth_roles'])
                ->where('oauth_client_id', $providerClient->client_id)
                ->pluck('passport_id')
                ->all();

            auth()->login($user, true);

            $this->doAfterLoginActions($providerClients, $socialiteUser, $user);
            $this->authInTheChat($user, $login);
        } catch (\Exception $e) {
            return redirect('/login');
        }
        return redirect($this->redirectTo);
    }

    /**
     * Выполнить действия после авторизации
     * @param $providerClients
     * @param $socialiteUser
     * @param $user
     */
    protected function doAfterLoginActions($providerClients, $socialiteUser, $user)
    {
        $actions = OauthLoginAction::whereIn('provider_client_id', $providerClients)->where([
            ['name', '!=', null],
            ['source', '!=', null],
            ['model_class', '!=', null],
            ['data', '!=', null],
            ['status', 1],
        ])->get();
        if ($actions) {
            foreach ($actions as $action) {
                $model = '\\' . $action->model_class;
                $modelObj = $model;
                $data = [];
                $arr2 = [];
                $attributes = $this->getModelFillableData($socialiteUser, $user, $action);
                foreach ($attributes as $attr => $val) {
                    $data[$attr] = $val;
                    if ($attr == 'user_id') {
                        $arr2[$attr] = $val;
                    }
                }
                $modelObj::updateOrCreate($arr2, $data);
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
     * @param $login
     */
    protected function authInTheChat($user, $login)
    {
        $res = $this->socialAccountsService->sendCurlToChatAuth($login);

        $res = $res['error'] ?? $res['success'];

        setcookie("chat_login_response", $res, [
            'expires' => time() + 3600,
            'path' => '/',
            'domain' => 'regagro.net',
        ]);
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
