<?php


namespace Modules\Oauth2\Entities;


use Illuminate\Database\Eloquent\Model;

class OauthProviderClient extends Model
{
    protected $fillable = [
        'provider_id',
        'client_id',
        'client_secret',
        'host',
        'role_id'
    ];

    public function provider()
    {
        return $this->belongsTo(OauthProvider::class);
    }
}
