<?php

namespace Modules\Oauth2\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Modules\Oauth2\Entities\OauthProvider;
use Modules\Oauth2\Entities\OauthProviderClient;
use Modules\Oauth2\Entities\SocialAccount;

class Oauth2Controller extends Controller
{
    /**
     * Файл настроек сервисов
     * @var string
     */
    protected $serviceConfigFile;

    /**
     * Oauth2Controller constructor.
     */
    public function __construct()
    {
        $this->serviceConfigFile = module_path('Oauth2','Config/services.php');
    }

    /**
     * Показать панель управления плагином
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function dashboard()
    {
        $providers = OauthProvider::where('status', 'installed')->get();
        $providerClients = OauthProviderClient::all();
        $socialAccounts = SocialAccount::all();
        return view('oauth2::dashboard', compact('providers', 'providerClients', 'socialAccounts'));
    }

    /**
     * Показать форму установки провайдера
     * @return \Illuminate\Http\JsonResponse
     */
    public function showInstallProviderForm()
    {
        $providers = OauthProvider::where('status', 'not installed')->get();
        $view = View::make('oauth2::install_provider_form', compact('providers'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Установить нового провайдера
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function installProvider(Request $request)
    {
        $data = $request->all();
        $data['status'] = 'installed';
        $provider = OauthProvider::find($request->provider_id);
        $provider->update($data);
        $providers = OauthProvider::where('status', 'installed')->get();
        $view = View::make('oauth2::providers_table', compact('providers'))->render();
        return response()->json([
            'content' => $view,
            'disabled' => $providers->isEmpty()
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Показать форму добавления клиента
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function showAddProviderClientForm(Request $request)
    {
        $providers = OauthProvider::where('status','installed')->get();
        $view = View::make('oauth2::add_provider_form', compact('providers'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Показать форму изменения клиента
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function showEditProviderClientForm(Request $request, $provider_client_id)
    {
        $providerClient = OauthProviderClient::find($provider_client_id);
        $providers = OauthProvider::where('status','installed')->get();
        $view = View::make('oauth2::edit_provider_form', compact('providers', 'providerClient'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Добавить нового клиента
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function addProviderClient(Request $request)
    {
        $this->validate($request, [
            'provider_id' => 'required',
            'client_id' => 'required',
            'client_secret' => 'required',
        ]);
        $providerClient = new OauthProviderClient($request->all());
        $providerClient->save();
        $providerClients = OauthProviderClient::all();
        $view = View::make('oauth2::client_table', compact('providerClients'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Изменить данные клиента
     * @param Request $request
     * @param $provider_client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function editProviderClient(Request $request, $provider_client_id)
    {
        $providerClient = OauthProviderClient::find($provider_client_id);
        $providerClient->update($request->all());
        $providerClients = OauthProviderClient::all();
        $view = View::make('oauth2::client_table', compact('providerClients'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Удалить клиента
     * @param $provider_client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProviderClient($provider_client_id)
    {
        $providerClient = OauthProviderClient::find($provider_client_id);
        $providerClient->delete();
        $providerClients = OauthProviderClient::all();
        $view = View::make('oauth2::client_table', compact('providerClients'))->render();
        return response()->json(['content' => $view], 200);
    }

    /**
     * Проверить, существует ли настройка провайдера в файле конфигурации
     * @param $provider
     * @param $file
     * @return bool
     */
    protected function providerSettingsExists($provider, $file)
    {
        $fileContent = file($file, 2);
        foreach ($fileContent as $line => $content) {
            if (Str::contains($content, $provider->name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Записать дефолтную конфигурацию провайдера в файл конфигурации
     * @param $provider
     * @param $file
     * @return false|int
     */
    protected function writeProviderConfig($provider, $file)
    {
        $tab = '    ';
        $fileContent = file($file, 2);
        $settings = [
            $tab . $tab,
            $tab . "'$provider->name' => [",
            $tab . $tab ."'client_id' => '',",
            $tab . $tab ."'client_secret' => '',",
            $tab . $tab ."'redirect' => '',"
        ];
        if ($provider->name == 'laravelpassport') $settings[] = $tab . $tab ."'host' => '',";
        $settings[] = $tab . '],';
        foreach ($fileContent as $line => $content) {
            if (Str::contains($content, '];')) {
                for ($i = $line, $s = 0; $s < count($settings); $i++, $s++) {
                    array_splice($fileContent, $i, 0, $settings[$s]);;
                }
                break;
            }
        }
        return file_put_contents($file, implode(PHP_EOL, $fileContent));
    }
}
