<div class="modal-header">
    <h5 class="modal-title" id="staticBackdropLabel">Add new provider client</h5>
    <button type="button" class="close" aria-label="Close" data-dismiss="modal">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<div class="modal-body">
    <form class="data_form" action="{{ url('/plugins/oauth2/provider_clients') }}" method="post">
        <div class="form-group">
            <label for="name">Provider name:</label>
            <select class="form-control" id="name" name="provider_id" required>
                @foreach($providers as $provider)
                    <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="client_id" class="col-form-label">Client ID:</label>
            <input type="text" class="form-control" id="client_id" name="client_id" required>
        </div>
        <div class="form-group">
            <label for="client_secret" class="col-form-label">Client secret:</label>
            <input type="text" class="form-control" id="client_secret" name="client_secret" required>
        </div>
        <div class="form-group">
            <label for="host" class="col-form-label">Host:</label>
            <input type="text" class="form-control" id="host" name="host">
        </div>
    </form>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary " data-dismiss="modal">Close</button>
    <button type="submit" class="btn btn-primary save_provider_client" data-method="post" data-url="{{ url('/plugins/oauth2/provider_clients') }}">Save</button>
</div>
