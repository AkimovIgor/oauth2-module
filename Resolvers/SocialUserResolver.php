<?php


namespace Modules\Oauth2\Resolvers;


use Coderello\SocialGrant\Resolvers\SocialUserResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Facades\Socialite;
use Modules\Oauth2\Entities\OauthProviderClient;
use Modules\Oauth2\Services\SocialAccountsService;

class SocialUserResolver implements SocialUserResolverInterface
{
    /**
     * @var SocialAccountsService
     */
    private $socialAccountsService;

    /**
     * SocialUserResolver constructor.
     * @param SocialAccountsService $socialAccountsService
     */
    public function __construct(SocialAccountsService $socialAccountsService)
    {
        $this->socialAccountsService = $socialAccountsService;
    }

    /**
     * @param string $provider
     * @param string $accessToken
     * @return Authenticatable|null
     */
    public function resolveUserByProviderCredentials(string $provider, string $accessToken): ?Authenticatable
    {
        $providerUser = null;
        $providerClient = OauthProviderClient::find(session()->get('provider_client_id'));

        try {
            $providerUser = Socialite::driver($provider)->userFromToken($accessToken);
        } catch (\Exception $exception) {}

        if ($providerUser) {
            return $this->socialAccountsService->findOrCreateUser($provider, $providerClient, $providerUser);
        }

        return null;
    }
}
