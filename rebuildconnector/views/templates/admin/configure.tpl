{*
 * PrestaFlow — Rebuild Connector
 * Page de configuration BO — v1.3.0
 * Structure : Bandeau état · Utilisateurs & accès · Notifications FCM · Sécurité · Avancé
 *}

{* ─────────────────────────────────────────────────────────────────────────────
   BANDEAU MISE À JOUR DISPONIBLE
   ─────────────────────────────────────────────────────────────────────────────*}
{if $update_info}
<div class="alert alert-warning" style="margin-bottom:16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <i class="icon-download" style="font-size:20px; flex-shrink:0;"></i>
    <div style="flex:1; min-width:200px;">
        <strong>Mise à jour disponible : Rebuild Connector v{$update_info.latest|escape:'htmlall'}</strong>
        &nbsp;&mdash;&nbsp;
        version installée : <code>v{$module_version|escape:'htmlall'}</code>
    </div>
    <div style="white-space:nowrap; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
        {if $update_info.download_url}
        <a href="{$update_info.download_url|escape:'htmlall'}" target="_blank" rel="noopener"
           class="btn btn-default btn-sm" style="margin-right:2px;">
            <i class="icon-download"></i> Télécharger
        </a>
        {/if}
        {if $update_info.url}
        <a href="{$update_info.url|escape:'htmlall'}" target="_blank" rel="noopener"
           class="btn btn-default btn-sm">
            <i class="icon-external-link"></i> Voir la release
        </a>
        {/if}
    </div>
</div>
{/if}

{* ─────────────────────────────────────────────────────────────────────────────
   SECTION 1 — BANDEAU D'ÉTAT
   ─────────────────────────────────────────────────────────────────────────────*}
<div class="panel panel-default" id="rbc-status-panel">
    <div class="panel-heading">
        <i class="icon-signal"></i>
        Rebuild Connector — État du module
        <span class="badge" style="float:right; background:#6c757d;">v{$module_version|escape:'htmlall'}</span>
    </div>
    <div class="panel-body">
        <div class="row">

            <div class="col-lg-3 col-md-6" style="margin-bottom:12px;">
                <div class="well well-sm text-center" style="min-height:80px; padding:12px;">
                    <div style="font-size:22px; color:#5cb85c;"><i class="icon-check-circle"></i></div>
                    <strong>Module actif</strong><br>
                    <small class="text-muted">{$settings.shop_url|escape:'htmlall'}</small>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" style="margin-bottom:12px;">
                <div class="well well-sm text-center" style="min-height:80px; padding:12px;">
                    {if $settings.hub_enabled}
                        <div style="font-size:22px; color:#5cb85c;"><i class="icon-cloud"></i></div>
                        <strong>Hub push actif</strong><br>
                        <small class="text-muted">push.rebuild-it.fr</small>
                    {else}
                        <div style="font-size:22px; color:#d9534f;"><i class="icon-cloud"></i></div>
                        <strong style="color:#d9534f;">Hub push inactif</strong><br>
                        <small class="text-muted">Clé de licence manquante</small>
                    {/if}
                </div>
            </div>

            <div class="col-lg-3 col-md-6" style="margin-bottom:12px;">
                <div class="well well-sm text-center" style="min-height:80px; padding:12px;">
                    {if $settings.rate_limit_enabled}
                        <div style="font-size:22px; color:#5cb85c;"><i class="icon-shield"></i></div>
                        <strong>Rate-limit actif</strong><br>
                        <small class="text-muted">{$settings.rate_limit|intval} req/min</small>
                    {else}
                        <div style="font-size:22px; color:#f0ad4e;"><i class="icon-shield"></i></div>
                        <strong style="color:#f0ad4e;">Rate-limit désactivé</strong><br>
                        <small class="text-muted">Aucune limite de débit</small>
                    {/if}
                </div>
            </div>

            <div class="col-lg-3 col-md-6" style="margin-bottom:12px;">
                <div class="well well-sm text-center" style="min-height:80px; padding:12px;">
                    <div style="font-size:22px; color:#337ab7;"><i class="icon-group"></i></div>
                    <strong>{$users_count|intval} utilisateur{if $users_count != 1}s{/if}</strong><br>
                    <small class="text-muted">+ 1 accès Admin (clé globale)</small>
                </div>
            </div>

        </div>

        <div class="row">
            <div class="col-lg-12">
                <p class="text-muted" style="margin:0; font-size:12px;">
                    <strong>URL API :</strong>
                    <code>{$settings.api_pretty_url|escape:'htmlall'}</code>
                    &nbsp;·&nbsp;
                    <strong>TTL JWT :</strong> {$settings.token_ttl|intval}s
                </p>
            </div>
        </div>
    </div>
    <div class="panel-footer" style="text-align:right; padding:8px 15px;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="token" value="{$smarty.get.token|escape:'htmlall'}">
            <button type="submit" name="rebuildconnector_check_update" value="1" class="btn btn-default btn-sm">
                <i class="icon-refresh"></i> Vérifier la mise à jour
            </button>
        </form>
    </div>
</div>


{* ─────────────────────────────────────────────────────────────────────────────
   SECTION 2 — UTILISATEURS & ACCÈS
   ─────────────────────────────────────────────────────────────────────────────*}
<div class="panel" id="rbc-users-panel">
    <div class="panel-heading">
        <i class="icon-group"></i>
        Utilisateurs &amp; accès
    </div>
    <div class="panel-body">

        {* Alerte clé régénérée (admin legacy) — affichage one-time uniquement *}
        {if isset($regenerated_admin_api_key) && $regenerated_admin_api_key}
            <div class="alert alert-warning" id="rbc-admin-regen-alert">
                <i class="icon-warning-sign"></i>
                <strong>Clé Admin régénérée — notez-la et scannez le QR maintenant, elle ne sera plus affichée.</strong><br>
                <span class="text-muted" style="font-size:12px;">Cette clé donne un accès complet à tous les endpoints. Conservez-la en lieu sûr.</span><br><br>
                Clé : <code id="rbc-admin-regen-key">{$regenerated_admin_api_key|escape:'htmlall'}</code>
                {if isset($regenerated_admin_qr_json) && $regenerated_admin_qr_json}
                    <div
                        id="rbc_admin_regen_qr_container"
                        class="well text-center"
                        style="margin-top:12px; display:inline-block;"
                        data-qr-config="{$regenerated_admin_qr_json|escape:'htmlall'}"
                    >
                        <div data-role="rbc-qr-render"></div>
                    </div>
                {/if}
                <p class="help-block" style="margin-top:8px;">
                    <i class="icon-info-sign"></i>
                    Pour connecter un appareil supplémentaire, préférez créer un <strong>utilisateur nommé</strong> dédié
                    (section ci-dessous) plutôt que de partager cette clé Admin.
                </p>
            </div>
        {/if}

        {* Alerte clé nouvel utilisateur ou clé régénérée *}
        {if isset($new_user_api_key) && $new_user_api_key}
            <div class="alert alert-success" id="rbc-new-user-alert">
                <i class="icon-check"></i>
                <strong>Clé API générée — notez-la maintenant, elle ne sera plus affichée !</strong><br>
                Clé : <code id="rbc-new-user-key">{$new_user_api_key|escape:'htmlall'}</code>
                {if isset($new_user_qr_json) && $new_user_qr_json}
                    <div
                        id="rbc_user_qr_container"
                        class="well text-center"
                        style="margin-top:12px; display:inline-block;"
                        data-qr-config="{$new_user_qr_json|escape:'htmlall'}"
                    >
                        <div data-role="rbc-qr-render"></div>
                    </div>
                {/if}
            </div>
        {/if}

        {* ── Carte Admin (clé globale legacy) ── *}
        <div class="panel panel-default" style="border-left: 4px solid #337ab7;">
            <div class="panel-heading" style="background:#f5f5f5;">
                <i class="icon-star"></i>
                <strong>Admin</strong>
                <span class="label label-primary" style="margin-left:8px;">Accès complet — tous les scopes</span>
                <span class="text-muted" style="font-size:12px; margin-left:12px;">Clé API globale (compatibilité)</span>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-lg-8">
                        <p>
                            Clé API :
                            {if $settings.api_key_configured}
                                <code style="color:#888; letter-spacing:2px;">••••••••••••••••••••</code>
                                <span class="text-muted" style="font-size:12px; margin-left:8px;">
                                    Clé secrète — visible uniquement lors de la régénération
                                </span>
                            {else}
                                <span class="label label-danger">Aucune clé configurée</span>
                            {/if}
                        </p>
                        <p class="text-muted" style="font-size:12px;">
                            Cette clé donne un accès complet à tous les endpoints. Elle est utilisée par l'app en mode legacy
                            (sans utilisateur nommé). La régénérer invalide immédiatement l'accès avec l'ancienne clé.
                            Pour connecter un appareil supplémentaire, préférez créer un <strong>utilisateur nommé</strong> dédié.
                        </p>
                    </div>
                    <div class="col-lg-4 text-right">
                        <form method="post" style="display:inline;">
                            <button
                                type="submit"
                                name="rebuildconnector_regenerate_admin_key"
                                value="1"
                                class="btn btn-warning btn-sm"
                                onclick="return confirm('Régénérer la clé Admin ? L\'ancienne clé sera immédiatement invalide et non récupérable.');"
                            >
                                <i class="icon-refresh"></i> Régénérer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {* ── Liste des utilisateurs nommés ── *}
        {if isset($users) && $users|@count > 0}
            <h4 style="margin-top:20px; margin-bottom:12px;">
                <i class="icon-user"></i>
                Utilisateurs nommés ({$users_count|intval})
            </h4>

            {foreach from=$users item=u}
                {assign var="u_scopes" value=$u.scopes_array}
                <div class="panel panel-default rbc-user-card" style="border-left: 4px solid {if $u.active}#5cb85c{else}#d9534f{/if};">
                    <div class="panel-heading" style="background:#fafafa; padding:8px 15px;">
                        <div class="row">
                            <div class="col-lg-6">
                                <strong>{$u.label|escape:'htmlall'}</strong>
                                &nbsp;
                                {if $u.active}
                                    <span class="label label-success">Actif</span>
                                {else}
                                    <span class="label label-danger">Révoqué</span>
                                {/if}
                                <br>
                                <small class="text-muted">
                                    <i class="icon-user-md"></i>
                                    {$u.employee_firstname|escape:'htmlall'} {$u.employee_lastname|escape:'htmlall'}
                                    &lt;{$u.employee_email|escape:'htmlall'}&gt;
                                    — créé le {$u.date_add|date_format:'%d/%m/%Y'}
                                </small>
                            </div>
                            <div class="col-lg-6 text-right">
                                {* QR / Régénérer *}
                                <button
                                    type="button"
                                    class="btn btn-default btn-xs"
                                    data-toggle="collapse"
                                    data-target="#rbc-user-scopes-{$u.id_user|intval}"
                                >
                                    <i class="icon-edit"></i> Scopes
                                </button>
                                &nbsp;
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="rebuildconnector_user_id" value="{$u.id_user|intval}">
                                    <button
                                        type="submit"
                                        name="rebuildconnector_regenerate_user_key"
                                        value="1"
                                        class="btn btn-warning btn-xs"
                                        onclick="return confirm('Régénérer la clé API de {$u.label|escape:'javascript'} ? L\'ancienne clé sera immédiatement invalide.');"
                                    >
                                        <i class="icon-refresh"></i> Régénérer la clé
                                    </button>
                                </form>
                                &nbsp;
                                {if $u.active}
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="rebuildconnector_user_id" value="{$u.id_user|intval}">
                                        <input type="hidden" name="rebuildconnector_user_active" value="0">
                                        <button
                                            type="submit"
                                            name="rebuildconnector_toggle_user"
                                            value="1"
                                            class="btn btn-danger btn-xs"
                                            onclick="return confirm('Révoquer l\'accès de {$u.label|escape:'javascript'} ?');"
                                        >
                                            <i class="icon-ban-circle"></i> Révoquer
                                        </button>
                                    </form>
                                {else}
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="rebuildconnector_user_id" value="{$u.id_user|intval}">
                                        <input type="hidden" name="rebuildconnector_user_active" value="1">
                                        <button
                                            type="submit"
                                            name="rebuildconnector_toggle_user"
                                            value="1"
                                            class="btn btn-success btn-xs"
                                        >
                                            <i class="icon-check"></i> Réactiver
                                        </button>
                                    </form>
                                {/if}
                            </div>
                        </div>
                    </div>

                    <div class="panel-body" style="padding:8px 15px;">
                        {* Scopes en lecture *}
                        <div>
                            {if $u_scopes && $u_scopes|@count > 0}
                                {foreach from=$u_scopes item=s}
                                    <span class="label label-info" style="margin:2px; display:inline-block;">
                                        {$s|escape:'htmlall'}
                                    </span>
                                {/foreach}
                            {else}
                                <span class="text-muted">Aucun scope</span>
                            {/if}
                        </div>

                        {* Panel modification scopes (masqué par défaut) *}
                        <div id="rbc-user-scopes-{$u.id_user|intval}" class="collapse" style="margin-top:12px; padding-top:12px; border-top:1px solid #eee;">
                            <form method="post">
                                <input type="hidden" name="rebuildconnector_user_id" value="{$u.id_user|intval}">
                                <div class="row">

                                    {* Presets de rôle *}
                                    <div class="col-lg-12" style="margin-bottom:8px;">
                                        <label style="font-weight:normal; margin-right:8px;">Preset :</label>
                                        {foreach from=$role_presets key=preset_key item=preset}
                                            <button
                                                type="button"
                                                class="btn btn-default btn-xs rbc-preset-btn"
                                                data-preset-key="{$preset_key|escape:'htmlall'}"
                                                data-scopes="{$preset.scopes|json_encode|escape:'htmlall'}"
                                                data-user="{$u.id_user|intval}"
                                                style="margin-right:4px;"
                                            >
                                                {$preset.label|escape:'htmlall'}
                                            </button>
                                        {/foreach}
                                    </div>

                                    {* Cases à cocher scopes *}
                                    {foreach from=$available_scopes item=scope}
                                        <div class="col-lg-3 col-md-4 col-sm-6">
                                            <div class="checkbox" style="margin:2px 0;">
                                                <label>
                                                    <input
                                                        type="checkbox"
                                                        name="rebuildconnector_user_scopes[]"
                                                        value="{$scope|escape:'htmlall'}"
                                                        class="rbc-scope-check"
                                                        data-user="{$u.id_user|intval}"
                                                        {if $u_scopes && in_array($scope, $u_scopes)}checked{/if}
                                                    >
                                                    <code style="font-size:11px;">{$scope|escape:'htmlall'}</code>
                                                </label>
                                            </div>
                                        </div>
                                    {/foreach}
                                </div>
                                <div style="margin-top:8px;">
                                    <button
                                        type="submit"
                                        name="rebuildconnector_update_scopes"
                                        value="1"
                                        class="btn btn-primary btn-sm"
                                    >
                                        <i class="icon-save"></i> Enregistrer les scopes
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-default btn-sm"
                                        data-toggle="collapse"
                                        data-target="#rbc-user-scopes-{$u.id_user|intval}"
                                    >
                                        Annuler
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            {/foreach}
        {else}
            <p class="text-muted" style="margin-top:16px;">
                <i class="icon-info-sign"></i>
                Aucun utilisateur nommé configuré. Créez un utilisateur ci-dessous.
            </p>
        {/if}

        {* ── Formulaire création utilisateur ── *}
        <div class="panel panel-default" style="margin-top:20px; border-left: 4px solid #5bc0de;">
            <div class="panel-heading" style="background:#f0f8ff;">
                <i class="icon-plus"></i>
                <strong>Ajouter un utilisateur</strong>
            </div>
            <div class="panel-body">
                <form method="post" class="form-horizontal">

                    <div class="form-group">
                        <label class="control-label col-lg-3" for="rbc_new_employee">Employé PrestaShop</label>
                        <div class="col-lg-9">
                            <select id="rbc_new_employee" name="rebuildconnector_user_employee" class="form-control" required>
                                <option value="">— Sélectionner un employé —</option>
                                {if isset($employees) && $employees}
                                    {foreach from=$employees item=emp}
                                        <option value="{$emp.id_employee|intval}">
                                            {$emp.firstname|escape:'htmlall'} {$emp.lastname|escape:'htmlall'}
                                            ({$emp.email|escape:'htmlall'})
                                        </option>
                                    {/foreach}
                                {/if}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3" for="rbc_new_label">Label de l'utilisateur</label>
                        <div class="col-lg-9">
                            <input
                                type="text"
                                id="rbc_new_label"
                                name="rebuildconnector_user_label"
                                class="form-control"
                                maxlength="100"
                                placeholder="Ex : Préparateur entrepôt A"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">Rôle (preset)</label>
                        <div class="col-lg-9">
                            <div style="margin-bottom:8px;">
                                {foreach from=$role_presets key=preset_key item=preset}
                                    <button
                                        type="button"
                                        class="btn btn-default btn-sm rbc-preset-btn"
                                        data-preset-key="{$preset_key|escape:'htmlall'}"
                                        data-scopes="{$preset.scopes|json_encode|escape:'htmlall'}"
                                        data-user="new"
                                        style="margin-right:6px; margin-bottom:4px;"
                                    >
                                        {$preset.label|escape:'htmlall'}
                                    </button>
                                {/foreach}
                            </div>
                            <p class="help-block" style="margin-top:0;">
                                Cliquez sur un preset pour pré-cocher les scopes correspondants, puis affinez si besoin.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">Scopes</label>
                        <div class="col-lg-9">
                            <div class="row">
                                {foreach from=$available_scopes item=scope}
                                    <div class="col-lg-4 col-md-6">
                                        <div class="checkbox" style="margin:2px 0;">
                                            <label>
                                                <input
                                                    type="checkbox"
                                                    name="rebuildconnector_user_scopes[]"
                                                    value="{$scope|escape:'htmlall'}"
                                                    class="rbc-scope-check"
                                                    data-user="new"
                                                >
                                                <code style="font-size:11px;">{$scope|escape:'htmlall'}</code>
                                            </label>
                                        </div>
                                    </div>
                                {/foreach}
                            </div>
                            <p class="help-block">
                                Principe du moindre privilège : n'accordez que les scopes nécessaires à ce rôle.
                            </p>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="submit" name="rebuildconnector_create_user" value="1" class="btn btn-primary">
                            <i class="icon-plus"></i> Créer l'utilisateur
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>{* .panel-body *}
</div>{* #rbc-users-panel *}


{* ─────────────────────────────────────────────────────────────────────────────
   SECTION 3 — NOTIFICATIONS PUSH (hub-only)
   ─────────────────────────────────────────────────────────────────────────────*}


<div class="panel" id="rbc-hub-panel">
    <div class="panel-heading">
        <i class="icon-cloud"></i>
        Hub push centralisé (Rebuild IT)
    </div>
    <div class="panel-body">

        {if $settings.hub_enabled}
            <div class="alert alert-success" style="margin-bottom:16px;">
                <i class="icon-check"></i>
                <strong>Notifications push activées.</strong>
            </div>
        {else}
            <p class="text-muted" style="margin-bottom:12px;">
                Activez les notifications push en un clic : le module obtient automatiquement une licence
                auprès du hub. La saisie manuelle d'une clé reste possible dans « Avancé » si besoin.
            </p>

            {* ─── Auto-provisionnement : n'a de sens que tant que le hub n'est pas déjà actif ─── *}
            <div class="well" style="margin-bottom:15px;">
                <h4><i class="icon-magic"></i> Activation en un clic</h4>
                <p>
                    Le module peut demander automatiquement une licence d'essai au hub push pour ce
                    domaine (aucune saisie requise). Si une licence existe déjà pour ce domaine, elle ne
                    peut pas être renvoyée par le hub (secret one-time) — il faudra alors la saisir
                    manuellement dans « Avancé » ou contacter l'administrateur du hub.
                </p>
                <form method="post">
                    <button type="submit" name="rebuildconnector_hub_provision" value="1" class="btn btn-primary">
                        <i class="icon-cloud-upload"></i> Activer le push / Provisionner une licence
                    </button>
                </form>
            </div>
        {/if}
    </div>
</div>


{* ─────────────────────────────────────────────────────────────────────────────
   SECTION 4 — SÉCURITÉ
   ─────────────────────────────────────────────────────────────────────────────*}
<div class="panel" id="rbc-security-panel">
    <div class="panel-heading">
        <i class="icon-lock"></i>
        Sécurité
    </div>
    <div class="panel-body">
        <form method="post" class="form-horizontal">

            <div class="form-group">
                <label class="control-label col-lg-3">Clé JWT (secret de signature)</label>
                <div class="col-lg-9">
                    <p class="form-control-static">
                        <code>{$settings.jwt_secret_preview|escape:'htmlall'}</code>
                    </p>
                    <p class="help-block">{$i18n.jwt_help|escape:'htmlall'}</p>
                    <button
                        type="submit"
                        name="rebuildconnector_regenerate_secret"
                        value="1"
                        class="btn btn-warning btn-sm"
                        onclick="return confirm('{$i18n.regenerate_confirm|escape:'javascript'}');"
                    >
                        <i class="icon-refresh"></i> {$i18n.regenerate_button|escape:'htmlall'}
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3" for="rebuildconnector_token_ttl">Durée de vie du token (TTL)</label>
                <div class="col-lg-9">
                    <div class="input-group" style="max-width:200px;">
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
                        <span class="input-group-addon">secondes</span>
                    </div>
                    <p class="help-block">{$i18n.token_ttl_help|escape:'htmlall'}</p>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label class="control-label col-lg-3">Rate-limiting</label>
                <div class="col-lg-9">
                    <input type="hidden" name="REBUILDCONNECTOR_RATE_LIMIT_ENABLED" value="0">
                    <label class="checkbox-inline">
                        <input
                            type="checkbox"
                            name="REBUILDCONNECTOR_RATE_LIMIT_ENABLED"
                            value="1"
                            {if $settings.rate_limit_enabled}checked{/if}
                        >
                        Activer la limite de débit
                    </label>
                    <p class="help-block">{$i18n.rate_limit_enabled_help|escape:'htmlall'}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3" for="rebuildconnector_rate_limit">Limite (requêtes/min)</label>
                <div class="col-lg-9">
                    <div class="input-group" style="max-width:200px;">
                        <input
                            type="number"
                            min="1"
                            id="rebuildconnector_rate_limit"
                            name="REBUILDCONNECTOR_RATE_LIMIT"
                            value="{$settings.rate_limit|intval}"
                            class="form-control"
                        >
                        <span class="input-group-addon">req/min</span>
                    </div>
                    <p class="help-block">{$i18n.rate_limit_help|escape:'htmlall'}</p>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label class="control-label col-lg-3" for="rebuildconnector_allowed_ips">Allowlist IP</label>
                <div class="col-lg-9">
                    <textarea
                        id="rebuildconnector_allowed_ips"
                        name="REBUILDCONNECTOR_ALLOWED_IPS"
                        rows="4"
                        class="form-control"
                        placeholder="192.168.1.0/24&#10;10.0.0.1"
                    >{$settings.allowed_ips|escape:'htmlall'}</textarea>
                    <p class="help-block">{$i18n.allowed_ips_help|escape:'htmlall'}</p>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label class="control-label col-lg-3" for="rebuildconnector_webhook_url">URL Webhook</label>
                <div class="col-lg-9">
                    <input
                        type="url"
                        id="rebuildconnector_webhook_url"
                        name="REBUILDCONNECTOR_WEBHOOK_URL"
                        value="{$settings.webhook_url|escape:'htmlall'}"
                        class="form-control"
                        placeholder="https://example.com/webhooks/rebuild"
                    >
                    <p class="help-block">{$i18n.webhook_url_help|escape:'htmlall'}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">Secret Webhook</label>
                <div class="col-lg-9">
                    <p class="form-control-static">
                        <code>{$settings.webhook_secret_preview|escape:'htmlall'}</code>
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
                            <input type="checkbox" name="REBUILDCONNECTOR_WEBHOOK_SECRET_CLEAR" value="1">
                            {$i18n.webhook_secret_clear_label|escape:'htmlall'}
                        </label>
                    </div>
                    <p class="help-block">{$i18n.webhook_secret_help|escape:'htmlall'}</p>
                </div>
            </div>

            <div class="panel-footer">
                <button type="submit" name="submitRebuildconnectorModule" value="1" class="btn btn-primary">
                    <i class="icon-save"></i> Enregistrer les paramètres de sécurité
                </button>
            </div>
        </form>
    </div>
</div>


{* ─────────────────────────────────────────────────────────────────────────────
   SECTION 5 — AVANCÉ / JOURNAUX (repliée)
   ─────────────────────────────────────────────────────────────────────────────*}
<div class="panel" id="rbc-advanced-panel">
    <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#rbc-advanced-body">
        <i class="icon-cog"></i>
        Avancé / Journaux
        <span class="pull-right text-muted" style="font-size:12px;">
            <i class="icon-chevron-down"></i>
        </span>
    </div>
    <div id="rbc-advanced-body" class="collapse">
        <div class="panel-body">
            <form method="post" class="form-horizontal">

                <div class="form-group">
                    <label class="control-label col-lg-3" for="rebuildconnector_scopes">
                        Scopes globaux (clé Admin)
                    </label>
                    <div class="col-lg-9">
                        <textarea
                            id="rebuildconnector_scopes"
                            name="REBUILDCONNECTOR_SCOPES"
                            rows="5"
                            class="form-control"
                        >{$settings.scopes_text|escape:'htmlall'}</textarea>
                        <p class="help-block">{$i18n.scopes_help|escape:'htmlall'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3" for="rebuildconnector_env_overrides">
                        Overrides d'environnement
                    </label>
                    <div class="col-lg-9">
                        <textarea
                            id="rebuildconnector_env_overrides"
                            name="REBUILDCONNECTOR_ENV_OVERRIDES"
                            rows="5"
                            class="form-control"
                            spellcheck="false"
                            placeholder="CLE=valeur"
                        >{$settings.env_overrides|escape:'htmlall'}</textarea>
                        <p class="help-block">{$i18n.env_overrides_help|escape:'htmlall'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">URL API</label>
                    <div class="col-lg-9">
                        <p class="form-control-static">
                            <strong>Pretty :</strong> <code>{$settings.api_pretty_url|escape:'htmlall'}</code>
                        </p>
                        {if $settings.api_legacy_url && $settings.api_legacy_url ne $settings.api_pretty_url}
                            <p class="form-control-static">
                                <strong>Legacy :</strong> <code>{$settings.api_legacy_url|escape:'htmlall'}</code>
                            </p>
                        {/if}
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" name="submitRebuildconnectorModule" value="1" class="btn btn-primary">
                        <i class="icon-save"></i> Enregistrer les paramètres avancés
                    </button>
                </div>
            </form>

            {* ─── Hub push : réglages de dépannage (l'activation normale = 1 clic dans « Hub push centralisé ») ─── *}
            <hr>
            <h4 style="margin-top:0;"><i class="icon-cloud"></i> Hub push — réglages avancés</h4>
            <p class="text-muted" style="margin-bottom:12px;">
                L'activation normale se fait en un clic depuis la section « Hub push centralisé ».
                Les réglages ci-dessous ne servent qu'au dépannage : saisie manuelle d'une clé de licence
                (si l'auto-provisionnement n'est pas possible) et backfill des devices déjà en base.
            </p>

            <form method="post" class="form-horizontal">
                <div class="form-group">
                    <label class="control-label col-lg-3" for="rebuildconnector_hub_license_key">Clé de licence</label>
                    <div class="col-lg-9">
                        {if $settings.hub_license_key_preview}
                            <p class="form-control-static">
                                <code>{$settings.hub_license_key_preview|escape:'htmlall'}</code>
                            </p>
                            <label class="checkbox-inline">
                                <input type="checkbox" name="REBUILDCONNECTOR_HUB_LICENSE_KEY_CLEAR" value="1">
                                Supprimer la clé de licence
                            </label>
                        {/if}
                        <input
                            type="password"
                            id="rebuildconnector_hub_license_key"
                            name="REBUILDCONNECTOR_HUB_LICENSE_KEY"
                            class="form-control"
                            spellcheck="false"
                            autocomplete="off"
                            placeholder="{if $settings.hub_license_key_preview}Laisser vide pour conserver la clé actuelle{else}rbk_…{/if}"
                        >
                        <p class="help-block">
                            Clé de licence fournie par le hub (secret partagé module ↔ hub, transmis en HTTPS).
                        </p>
                    </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" name="submitRebuildconnectorModule" value="1" class="btn btn-primary">
                        <i class="icon-save"></i> Enregistrer la clé de licence
                    </button>
                </div>
            </form>

            {if $settings.hub_enabled}
            <div class="well" style="margin-top:15px;">
                <h4><i class="icon-refresh"></i> Synchronisation des devices existants</h4>
                <p>
                    Relaye au hub tous les devices déjà enregistrés dans la base de données locale.
                    À lancer une fois après l'activation du hub pour que celui-ci connaisse vos appareils existants.
                    Opération idempotente (le hub fait un upsert) et best-effort (un échec sur un device n'interrompt pas les autres).
                </p>
                <form method="post">
                    <button
                        type="submit"
                        name="rebuildconnector_hub_sync_devices"
                        value="1"
                        class="btn btn-default"
                        onclick="return confirm('Lancer la synchronisation des devices vers le hub ?');"
                    >
                        <i class="icon-upload"></i> Synchroniser les devices vers le hub
                    </button>
                </form>
            </div>
            {/if}
        </div>
    </div>
</div>


{* ─────────────────────────────────────────────────────────────────────────────
   SCRIPTS
   ─────────────────────────────────────────────────────────────────────────────*}
<script type="text/javascript" src="{$module_dir|escape:'htmlall'}views/js/vendor/qrcode.js"></script>

<script type="text/javascript">
(function () {
    'use strict';

    // ── Génération QR générique pour tous les containers [data-qr-config] ──
    function renderQrInContainer(container) {
        if (!container || typeof QRCode === 'undefined') { return; }
        var raw = container.getAttribute('data-qr-config');
        if (!raw) { return; }
        var target = container.querySelector('[data-role="rbc-qr-render"]');
        if (!target) { return; }
        // Nettoyer le contenu précédent
        while (target.firstChild) { target.removeChild(target.firstChild); }
        try {
            var config = JSON.parse(raw);
            new QRCode(target, {
                text: JSON.stringify(config),
                width: 200,
                height: 200,
                correctLevel: QRCode.CorrectLevel.M
            });
            var statusEl = container.querySelector('[data-role="rbc-qr-status"]');
            if (statusEl) { statusEl.textContent = new Date().toLocaleString(); }
        } catch (e) {
            if (window.console) { console.error('[RebuildConnector] Erreur QR:', e); }
        }
    }

    // Rendu immédiat pour les alertes one-time (clé Admin régénérée / nouvel utilisateur)
    var immediateContainers = [
        document.getElementById('rbc_admin_regen_qr_container'),
        document.getElementById('rbc_user_qr_container')
    ];
    immediateContainers.forEach(function (c) { renderQrInContainer(c); });

    // ── Presets de rôles ──
    document.addEventListener('click', function (e) {
        var btn = e.target;
        // Remonter jusqu'au bouton preset si clic sur icône enfant
        while (btn && btn !== document) {
            if (btn.classList && btn.classList.contains('rbc-preset-btn')) { break; }
            btn = btn.parentNode;
        }
        if (!btn || !btn.classList || !btn.classList.contains('rbc-preset-btn')) { return; }

        var scopesRaw = btn.getAttribute('data-scopes');
        var userId = btn.getAttribute('data-user');
        if (!scopesRaw || !userId) { return; }

        var presetScopes;
        try {
            presetScopes = JSON.parse(scopesRaw);
        } catch (err) { return; }

        // Trouver les checkboxes du bon groupe (data-user)
        var checkboxes = document.querySelectorAll('.rbc-scope-check[data-user="' + userId + '"]');
        checkboxes.forEach(function (cb) {
            cb.checked = presetScopes.indexOf(cb.value) !== -1;
        });

        e.preventDefault();
    });

})();
</script>
