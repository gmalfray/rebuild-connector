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
            <label class="control-label col-lg-3">
                {$i18n.api_url_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <p class="form-control-static">
                    <code>{$settings.api_pretty_url|escape:'htmlall'}</code>
                </p>
                {if $settings.api_legacy_url && $settings.api_legacy_url ne $settings.api_pretty_url}
                    <p class="form-control-static">
                        <code>{$settings.api_legacy_url|escape:'htmlall'}</code>
                    </p>
                {/if}
                <p class="help-block">
                    {$i18n.api_url_help|escape:'htmlall'}
                </p>
                {if $settings.shop_url}
                    <p class="help-block">
                        <strong>{$i18n.shop_url_label|escape:'htmlall'}:</strong>
                        <code>{$settings.shop_url|escape:'htmlall'}</code>
                    </p>
                {/if}
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {$i18n.qr_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <div
                    id="rebuildconnector_qr_container"
                    class="well text-center"
                    data-config="{$qr_config_json|escape:'htmlall'}"
                >
                    <div data-role="qr-target"></div>
                </div>
                <button
                    type="button"
                    class="btn btn-default"
                    id="rebuildconnector_qr_refresh"
                >
                    <i class="icon-refresh"></i>
                    {$i18n.qr_refresh|escape:'htmlall'}
                </button>
                <p class="help-block">
                    <strong>{$i18n.qr_last_refresh|escape:'htmlall'}</strong>
                    <span data-role="qr-status">—</span>
                </p>
                <p class="help-block">
                    {$i18n.qr_help|escape:'htmlall'}
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
            <label class="control-label col-lg-3" for="rebuildconnector_fcm_topics">
                {$i18n.topics_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_fcm_topics"
                    name="REBUILDCONNECTOR_FCM_TOPICS"
                    rows="4"
                    class="form-control"
                >{$settings.fcm_topics|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {$i18n.topics_help|escape:'htmlall'}
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

        <div class="form-group">
            <label class="control-label col-lg-3">
                {$i18n.shipping_notif_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <input type="hidden" name="REBUILDCONNECTOR_SHIPPING_NOTIFICATION" value="0">
                <label class="checkbox-inline">
                    <input
                        type="checkbox"
                        name="REBUILDCONNECTOR_SHIPPING_NOTIFICATION"
                        value="1"
                        {if $settings.shipping_notification_enabled}checked{/if}
                    >
                    {$i18n.shipping_notif_help|escape:'htmlall'}
                </label>
            </div>
        </div>

        <hr>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_webhook_url">
                {$i18n.webhook_url_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <input
                    type="url"
                    id="rebuildconnector_webhook_url"
                    name="REBUILDCONNECTOR_WEBHOOK_URL"
                    value="{$settings.webhook_url|escape:'htmlall'}"
                    class="form-control"
                    placeholder="https://example.com/webhooks/rebuild"
                >
                <p class="help-block">
                    {$i18n.webhook_url_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {$i18n.webhook_secret_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <p class="form-control-static">
                    {$settings.webhook_secret_preview|escape:'htmlall'}
                </p>
                <input
                    type="password"
                    name="REBUILDCONNECTOR_WEBHOOK_SECRET"
                    value=""
                    class="form-control"
                    autocomplete="new-password"
                    placeholder="{$i18n.webhook_secret_placeholder|escape:'htmlall'}"
                >
                <div class="checkbox">
                    <label>
                        <input
                            type="checkbox"
                            name="REBUILDCONNECTOR_WEBHOOK_SECRET_CLEAR"
                            value="1"
                        >
                        {$i18n.webhook_secret_clear_label|escape:'htmlall'}
                    </label>
                </div>
                <p class="help-block">
                    {$i18n.webhook_secret_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {$i18n.rate_limit_enabled_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <input type="hidden" name="REBUILDCONNECTOR_RATE_LIMIT_ENABLED" value="0">
                <label class="checkbox-inline">
                    <input
                        type="checkbox"
                        name="REBUILDCONNECTOR_RATE_LIMIT_ENABLED"
                        value="1"
                        {if $settings.rate_limit_enabled}checked{/if}
                    >
                    {$i18n.rate_limit_enabled_toggle|escape:'htmlall'}
                </label>
                <p class="help-block">
                    {$i18n.rate_limit_enabled_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_rate_limit">
                {$i18n.rate_limit_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <input
                    type="number"
                    min="1"
                    id="rebuildconnector_rate_limit"
                    name="REBUILDCONNECTOR_RATE_LIMIT"
                    value="{$settings.rate_limit|intval}"
                    class="form-control"
                >
                <p class="help-block">
                    {$i18n.rate_limit_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_allowed_ips">
                {$i18n.allowed_ips_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_allowed_ips"
                    name="REBUILDCONNECTOR_ALLOWED_IPS"
                    rows="4"
                    class="form-control"
                >{$settings.allowed_ips|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {$i18n.allowed_ips_help|escape:'htmlall'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="rebuildconnector_env_overrides">
                {$i18n.env_overrides_label|escape:'htmlall'}
            </label>
            <div class="col-lg-9">
                <textarea
                    id="rebuildconnector_env_overrides"
                    name="REBUILDCONNECTOR_ENV_OVERRIDES"
                    rows="5"
                    class="form-control"
                    spellcheck="false"
                >{$settings.env_overrides|escape:'htmlall'}</textarea>
                <p class="help-block">
                    {$i18n.env_overrides_help|escape:'htmlall'}
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

{if isset($qr_config_json)}
    <script type="text/javascript" src="{$module_dir|escape:'htmlall'}views/js/vendor/qrcode.js"></script>
    <script type="text/javascript" src="{$module_dir|escape:'htmlall'}views/js/admin/configure-qrcode.js"></script>
{/if}
