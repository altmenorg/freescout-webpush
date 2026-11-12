@extends('layouts.app')

@section('title', __('webpush::messages.share_title'))

@section('content')
{{-- What was shared stays in the phone's cache (service worker) until it is sent: the module's script (javascript
     hook, never inline: FreeScout's Content-Security-Policy) shows it here and sends it where the agent chooses. --}}
<div class="container webpush-share"
     data-note-url="{{ route('webpush.share.note') }}"
     data-new-url="{{ route('conversations.create', ['mailbox_id' => '__MAILBOX__']) }}">
    <h3 class="webpush-share-title">{{ __('webpush::messages.share_title') }}</h3>

    <div class="webpush-share-empty hidden">
        <p>{{ __('webpush::messages.share_nothing') }}</p>
    </div>

    <div class="webpush-share-body hidden">
        <div class="webpush-share-preview">
            <p class="webpush-share-text"></p>
            <ul class="webpush-share-files"></ul>
        </div>

        <div class="webpush-share-choice">
            <h4>{{ __('webpush::messages.share_to_ticket') }}</h4>
            <p class="text-help">{{ __('webpush::messages.share_to_ticket_help') }}</p>
            <div class="webpush-share-row">
                <input type="text" class="form-control webpush-share-ticket" list="webpush-share-recent" inputmode="numeric"
                       placeholder="{{ __('webpush::messages.share_ticket_number') }}">
                <datalist id="webpush-share-recent">
                    @foreach ($recent as $conversation)
                        <option value="{{ $conversation->number }}">#{{ $conversation->number }} {{ $conversation->subject }}</option>
                    @endforeach
                </datalist>
                <button type="button" class="btn btn-primary webpush-share-note">{{ __('webpush::messages.share_add_note') }}</button>
            </div>
        </div>

        <div class="webpush-share-choice">
            <h4>{{ __('webpush::messages.share_new_ticket') }}</h4>
            <div class="webpush-share-row">
                <select class="form-control webpush-share-mailbox">
                    @foreach ($mailboxes as $mailbox)
                        <option value="{{ $mailbox->id }}">{{ $mailbox->name }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-default webpush-share-new">{{ __('webpush::messages.share_open_form') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection
