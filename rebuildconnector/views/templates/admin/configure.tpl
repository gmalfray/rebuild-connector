<div class="panel">
    <h3>{$i18n.title|escape:'htmlall'}</h3>

    <form method="post" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_api_key">
                {$i18n.api_key_label|escape:'htmlall'}
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
                    {$i18n.api_key_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_token_ttl">
                {$i18n.token_ttl_label|escape:'htmlall'}
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
                    {$i18n.token_ttl_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_scopes">
                {$i18n.scopes_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_scopes"
                    name="REBUILDCONNECTOR_SCOPES"
                    rows="4"
                    class="form-control"
                >{$settings.scopes_text|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {$i18n.scopes_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {$i18n.jwt_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <p class="form-control-static">
                    {$settings.jwt_secret_preview|escape:'htmlall'}
                </p>
                <p class="help-block">
                    {$i18n.jwt_help|escape:'htmlall'}
                </p>
                <button
                    type="submit"
                    name="rebuildconnector_regenerate_secret"
                    value="1"
                    class="btn btn-warning"
                    onclick="return confirm('{$i18n.regenerate_confirm|escape:'javascript'}');"
                >
                    <i class="icon-refresh"></i>
                    {$i18n.regenerate_button|escape:'htmlall'}
                </button>
            </div>
        </div>

        <hr>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_fcm_service_account">
                {$i18n.service_account_label|escape:'htmlall'}
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
                    {$i18n.service_account_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_fcm_device_tokens">
                {$i18n.device_tokens_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_fcm_device_tokens"
                    name="REBUILDCONNECTOR_FCM_DEVICE_TOKENS"
                    rows="5"
                    class="form-control"
                >{$settings.fcm_device_tokens|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {$i18n.device_tokens_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="submitRebuildconnectorModule" value="1" class="btn btn-primary">
                <i class="icon-save"></i>
                {$i18n.save_button|escape:'htmlall'}
            </button>
        </div>
    </form>
</div>
