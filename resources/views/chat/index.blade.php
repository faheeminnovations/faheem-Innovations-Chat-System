@extends('layouts.app')

@section('title', 'Faheem Innovations')

@section('content')
<div id="app"
     class="flex h-screen overflow-hidden"
     data-auth-id="{{ $authUser->id }}"
     data-auth-name="{{ $authUser->name }}"
     data-auth-avatar="{{ $authUser->avatar }}"
    data-is-admin="{{ $authUser->isAdmin() ? '1' : '0' }}"
     data-initial-conversation="{{ $initialConversationId }}">

    {{-- Sidebar --}}
    <aside class="w-[320px] shrink-0 bg-white border-r border-gray-200 flex flex-col">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-200 shrink-0">
            <img src="{{ asset('images/faheem-innovations-logo.svg') }}" alt="Faheem Innovations" class="w-[190px] h-auto">
            <div class="flex items-center gap-1">
                <button id="notifications-btn" type="button" title="Enable message notifications"
                    class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600 text-lg leading-none">&#128276;</button>
                <button id="install-app-btn" type="button" title="Install app"
                    class="install-app-btn hidden">&#8615;</button>
                @if ($isAdmin)
                    <button id="invite-user-btn" type="button" title="Invite user"
                        class="admin-invite-btn">+ <span>Invite user</span></button>
                @endif
                <button id="new-chat-btn" title="New chat"
                    class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-600 text-xl leading-none">+</button>
            </div>
        </div>

        <div id="conversation-list" class="flex-1 overflow-y-auto">
            <button id="saved-messages-btn" type="button"
                class="w-full flex items-center gap-3 px-4 py-3 border-b border-gray-100 text-left hover:bg-blue-50 transition">
                <div class="avatar-circle w-11 h-11 text-sm bg-blue-100 text-blue-700">&#9733;</div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Saved messages</p>
                    <p class="text-xs text-gray-500 truncate">Message yourself</p>
                </div>
            </button>
            <div class="p-6 text-center text-sm text-gray-400">Loading chats…</div>
        </div>

        <div class="h-16 border-t border-gray-200 flex items-center justify-between px-4 shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold text-sm shrink-0">
                    {{ strtoupper(substr($authUser->name, 0, 1)) }}
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate">{{ $authUser->name }}</p>
                    <p class="text-xs text-green-600">● Online</p>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" title="Log out" class="text-gray-400 hover:text-red-500 text-sm">Log out</button>
            </form>
        </div>
    </aside>

    {{-- Main panel --}}
    <main class="flex-1 flex flex-col min-w-0">
        <div id="empty-state" class="flex-1 flex items-center justify-center">
            <div class="text-center text-gray-400">
                <div class="text-5xl mb-3">💬</div>
                <p class="text-sm">Select a chat or start a new one</p>
            </div>
        </div>

        <div id="chat-panel" class="hidden flex-1 flex flex-col min-h-0">
            <div id="chat-header" class="h-16 border-b border-gray-200 flex items-center px-5 gap-3 shrink-0"></div>

            <div id="messages-container" class="flex-1 overflow-y-auto px-5 py-4 space-y-3 bg-gray-50"></div>

            <div id="typing-indicator" class="px-5 text-xs text-gray-400 h-5 shrink-0"></div>

            <div id="upload-progress" class="upload-progress hidden" role="status" aria-live="polite">
                <div class="upload-progress-top">
                    <span class="upload-progress-status"><span class="upload-spinner" aria-hidden="true"></span><span id="upload-progress-label">Uploading file</span></span>
                    <span id="upload-progress-percent">0%</span>
                </div>
                <div class="upload-progress-track">
                    <div id="upload-progress-bar" class="upload-progress-bar"></div>
                </div>
            </div>

            <form id="message-form" class="border-t border-gray-200 p-3 flex items-end gap-2 shrink-0">
                <input type="file" id="file-input" accept="image/*,.zip,.pdf,.doc,.docx,.xls,.xlsx,.txt" class="hidden">
                <button type="button" id="attach-btn" title="Attach a file"
                    class="w-10 h-10 shrink-0 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500 text-lg">📎</button>
                <span class="upload-limit-hint" title="Maximum file size">Max 500 MB</span>
                <div id="file-preview" class="hidden text-xs text-gray-600 bg-gray-100 rounded-lg px-3 py-2 mr-1"></div>
                <textarea id="message-input" rows="1" placeholder="Type a message…"
                    class="flex-1 resize-none rounded-2xl border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 max-h-32"></textarea>
                <button type="submit" id="send-btn"
                    class="w-10 h-10 shrink-0 flex items-center justify-center rounded-full bg-indigo-600 hover:bg-indigo-700 text-white">➤</button>
            </form>
        </div>
    </main>

    {{-- New chat modal --}}
    <div id="new-chat-modal" class="hidden fixed inset-0 bg-black/30 flex items-center justify-center z-50">
        <div class="bg-white w-full max-w-md rounded-xl shadow-lg p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold text-gray-800">New conversation</h2>
                <button id="close-modal-btn" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            <div class="flex gap-2 mb-4">
                <button type="button" data-mode="private" class="chat-mode-btn flex-1 text-sm py-1.5 rounded-lg border border-indigo-600 bg-indigo-600 text-white">Direct message</button>
                <button type="button" data-mode="group" class="chat-mode-btn flex-1 text-sm py-1.5 rounded-lg border border-gray-300 text-gray-600">Group chat</button>
            </div>

            <div id="group-name-wrap" class="hidden mb-3">
                <input type="text" id="group-name-input" placeholder="Group name"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <input type="text" id="user-search-input" placeholder="Search people by name or email…"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">

            <button id="send-invite-from-chat-btn" type="button" class="admin-invite-modal-btn">
                + Send invite to a new user
            </button>

            <div id="selected-users" class="flex flex-wrap gap-1.5 mb-2"></div>

            <div id="user-search-results" class="max-h-56 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-100"></div>

            <button id="create-chat-btn" disabled
                class="mt-4 w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium py-2.5 rounded-lg transition">
                Start chat
            </button>
        </div>
    </div>

    <div id="invite-user-modal" class="hidden fixed inset-0 bg-black/30 flex items-center justify-center z-50">
        <div class="bg-white w-full max-w-md rounded-xl shadow-lg p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold text-gray-800">Invite user</h2>
                <button id="close-invite-modal-btn" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form id="invite-user-form" class="space-y-3">
                <input id="invite-name-input" type="text" required placeholder="User name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input id="invite-email-input" type="email" required placeholder="Email address" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input id="invite-password-input" type="password" required minlength="8" placeholder="Temporary password (8+ characters)" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2.5 rounded-lg">Send invite</button>
            </form>
        </div>
    </div>
</div>

<script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
@endsection
