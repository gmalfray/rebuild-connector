<div class="panel">
    <h3>{l s='Rebuild Connector — API & Notifications' mod='rebuildconnector'}</h3>

    <form method="post" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_api_key">
                {l s='API key' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <input
                    type="text"
                    id="rebuildconnector_api_key"
                    name="REBUILDCONNECTOR_API_KEY"
                    value="{$settings.api_key|escape:'htmlall'}"
                    class="form-control"
                    autocomplete="off"
                    required
                >
                <p class="help-block">
                    {l s='Key dedicated to the PrestaFlow mobile app. Share it securely with your team only.' mod='rebuildconnector'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_token_ttl">
                {l s='Token lifetime (seconds)' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <input
                    type="number"
                    min="300"
                    step="60"
                    id="rebuildconnector_token_ttl"
                    name="REBUILDCONNECTOR_TOKEN_TTL"
                    value="{$settings.token_ttl|intval}"
                    class="form-control"
                    required
                >
                <p class="help-block">
                    {l s='Duration before access tokens expire. Default: 3600 seconds (1 hour).' mod='rebuildconnector'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_scopes">
                {l s='Authorized scopes' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_scopes"
                    name="REBUILDCONNECTOR_SCOPES"
                    rows="4"
                    class="form-control"
                >{$settings.scopes_text|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {l s='One scope per line (e.g. orders.read, products.write). Leave empty to restore defaults.' mod='rebuildconnector'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='JWT secret' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <p class="form-control-static">
                    {$settings.jwt_secret_preview|escape:'htmlall'}
                </p>
                <p class="help-block">
                    {l s='Used to sign the tokens sent to the mobile app. Regenerate if you suspect a leak.' mod='rebuildconnector'}
                </p>
                <button
                    type="submit"
                    name="rebuildconnector_regenerate_secret"
                    value="1"
                    class="btn btn-warning"
                    onclick="return confirm('{l s='Regenerating the secret invalidates all current sessions. Continue?' mod='rebuildconnector' js=1}');"
                >
                    <i class="icon-refresh"></i>
                    {l s='Regenerate secret' mod='rebuildconnector'}
                </button>
            </div>
        </div>

        <hr>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_fcm_service_account">
                {l s='FCM service account (JSON)' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_fcm_service_account"
                    name="REBUILDCONNECTOR_FCM_SERVICE_ACCOUNT"
                    rows="8"
                    class="form-control"
                    spellcheck="false"
                >{$settings.fcm_service_account|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {l s='Paste the JSON content of your Firebase service account used for HTTP v1 notifications.' mod='rebuildconnector'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_fcm_device_tokens">
                {l s='Fallback device tokens' mod='rebuildconnector'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_fcm_device_tokens"
                    name="REBUILDCONNECTOR_FCM_DEVICE_TOKENS"
                    rows="5"
                    class="form-control"
                >{$settings.fcm_device_tokens|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {l s='Optional static tokens (one per line) used until the app registers users automatically.' mod='rebuildconnector'}
                </p>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="submitRebuildconnectorModule" value="1" class="btn btn-primary">
                <i class="icon-save"></i>
                {l s='Save settings' mod='rebuildconnector'}
            </button>
        </div>
    </form>
</div>
