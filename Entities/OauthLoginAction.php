<?php


namespace Modules\Oauth2\Entities;


use Illuminate\Database\Eloquent\Model;

class OauthLoginAction extends Model
{
    public $table = 'oauth_login_actions';

    protected $appends = ['data'];

    protected $fillable = [
        'provider_client_id',
        'name',
        'source',
        'model_class',
        'data',
        'status'
    ];

    public function getDataAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setDataAttribute($value)
    {
        $this->attributes['data'] = json_encode($value);
    }

    public function provider_client()
    {
        return $this->belongsTo(OauthProviderClient::class, 'provider_client_id');
    }
}
