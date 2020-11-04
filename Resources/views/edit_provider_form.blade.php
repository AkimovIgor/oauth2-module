<div class="modal-header">
    <h5 class="modal-title" id="staticBackdropLabel">Edit provider client</h5>
    <button type="button" class="close" aria-label="Close" data-dismiss="modal">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<div class="modal-body">
    <form class="data_form" action="{{ url('/plugins/oauth2/provider_clients/' . $providerClient->id) }}" method="post">
        {{ method_field('PUT') }}
        <div class="form-group">
            <label for="name">Provider name:</label>
            <select class="form-control" id="name" name="provider_id" required>
                @foreach($providers as $provider)
                    <option @if($provider->id == $providerClient->provider->id) selected @endif value="{{ $provider->id }}">{{ $provider->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="client_id" class="col-form-label">Client ID:</label>
            <input value="{{ $providerClient->client_id }}" type="text" class="form-control" id="client_id" name="client_id" required>
        </div>
        <div class="form-group">
            <label for="client_secret" class="col-form-label">Client secret:</label>
            <input value="{{ $providerClient->client_secret }}" type="text" class="form-control" id="client_secret" name="client_secret" required>
        </div>
        <div class="form-group">
            <label for="host" class="col-form-label">Host:</label>
            <input value="{{ $providerClient->host }}" type="text" class="form-control" id="host" name="host">
        </div>
    </form>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary " data-dismiss="modal">Close</button>
    <button type="submit" class="btn btn-primary save_provider_client" data-method="put" data-url="{{ url('/plugins/oauth2/provider_clients/' . $providerClient->id) }}">Save</button>
</div>
