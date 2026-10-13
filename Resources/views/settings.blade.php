<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('webpush::messages.settings_title') }}</h3>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <p class="text-help">{{ __('webpush::messages.settings_intro') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('webpush::messages.app_name') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[webpush.app_name]" value="{{ $settings['webpush.app_name'] }}" class="form-control input-sized-lg" placeholder="{{ config('app.name') }}" maxlength="45" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('webpush::messages.app_short_name') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[webpush.app_short_name]" value="{{ $settings['webpush.app_short_name'] }}" class="form-control input-sized" maxlength="20" />
            <p class="form-help">{{ __('webpush::messages.app_short_name_help') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('webpush::messages.app_icon') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[webpush.app_icon_url]" value="{{ $settings['webpush.app_icon_url'] }}" class="form-control input-sized-lg" placeholder="https://…/icon-512.png" />
            <p class="form-help">{{ __('webpush::messages.app_icon_help') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('webpush::messages.badge') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[webpush.badge_url]" value="{{ $settings['webpush.badge_url'] }}" class="form-control input-sized-lg" placeholder="https://…/badge-96.png" />
            <p class="form-help">{{ __('webpush::messages.badge_help') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('webpush::messages.contact') }}</label>
        <div class="col-sm-6">
            <input type="email" name="settings[webpush.push_contact]" value="{{ $settings['webpush.push_contact'] }}" class="form-control input-sized-lg" placeholder="{{ $default_contact }}" />
            <p class="form-help">{{ __('webpush::messages.contact_help') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('webpush::messages.save') }}</button>
        </div>
    </div>
</form>
