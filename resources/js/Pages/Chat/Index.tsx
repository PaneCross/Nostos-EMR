// ─── Chat/Index ───────────────────────────────────────────────────────────────
// Two-column real-time chat interface.
//
// Layout:
//   Left panel (w-72):  Channel list grouped by type with unread badges.
//                       "New Message" button opens a DM user-search input.
//   Right panel (flex): Message history (infinite scroll via pagination),
//                       real-time updates via Laravel Echo / Reverb.
//                       Input bar with urgent toggle + Enter-to-send.
//
// Real-time: subscribes to `private-chat.{channelId}` on channel select.
//            Incoming messages append to the list without page refresh.
//
// Accessibility: keyboard nav, focus management on send, ARIA labels.
// ──────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Channel {
    id: number;
    channel_type: 'direct' | 'department' | 'participant_idt' | 'broadcast';
    name: string;
    unread_count: number;
    is_active: boolean;
}

interface Message {
    id: number;
    channel_id: number;
    sender_user_id: number;
    sender_name: string;
    sender_initials: string;
    message_text: string | null;
    is_deleted: boolean;
    priority: 'standard' | 'urgent';
    sent_at: string;
    edited_at: string | null;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const CHANNEL_TYPE_LABELS: Record<string, string> = {
    direct:          'Direct Messages',
    participant_idt: 'Participant IDT',
    department:      'Department',
    broadcast:       'Broadcast',
};

const CHANNEL_TYPE_ORDER = ['direct', 'participant_idt', 'department', 'broadcast'];

function formatTime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDate(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

// ── MessageBubble ─────────────────────────────────────────────────────────────

function MessageBubble({ message, isMine }: { message: Message; isMine: boolean }) {
    const isUrgent  = message.priority === 'urgent';
    const isDeleted = message.is_deleted;

    return (
        <div
            data-testid={`message-${message.id}`}
            className={`flex gap-2.5 items-start ${isUrgent ? 'border-l-2 border-red-500 pl-2' : ''}`}
        >
            {/* Avatar */}
            <div
                className="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                style={{ backgroundColor: isMine ? '#3b82f6' : '#6b7280' }}
                aria-hidden="true"
            >
                {message.sender_initials}
            </div>

            <div className="flex-1 min-w-0">
                {/* Header */}
                <div className="flex items-baseline gap-2 mb-0.5">
                    <span className="text-xs font-semibold text-slate-800">{message.sender_name}</span>
                    <span className="text-[10px] text-slate-400">{formatTime(message.sent_at)}</span>
                    {isUrgent && (
                        <span className="text-[9px] font-bold uppercase tracking-wide bg-red-100 text-red-700 px-1.5 py-0.5 rounded">
                            URGENT
                        </span>
                    )}
                </div>

                {/* Body */}
                {isDeleted ? (
                    <p className="text-xs italic text-slate-400">This message was deleted</p>
                ) : (
                    <p className="text-sm text-slate-700 whitespace-pre-wrap break-words">
                        {message.message_text}
                    </p>
                )}
            </div>
        </div>
    );
}

// ── Main Component ─────────────────────────────────────────────────────────────

/** @component Chat/Index — Full-page real-time chat interface */
export default function ChatIndex() {
    const { auth } = usePage<PageProps>().props;
    const me = auth.user!;

    // ── State ──────────────────────────────────────────────────────────────────
    const [channels, setChannels]           = useState<Channel[]>([]);
    const [activeChannel, setActiveChannel] = useState<Channel | null>(null);
    const [messages, setMessages]           = useState<Message[]>([]);
    const [page, setPage]                   = useState(1);
    const [lastPage, setLastPage]           = useState(1);
    const [loadingMsgs, setLoadingMsgs]     = useState(false);
    const [input, setInput]                 = useState('');
    const [isUrgent, setIsUrgent]           = useState(false);
    const [sending, setSending]             = useState(false);
    const [dmQuery, setDmQuery]             = useState('');
    const [dmResults, setDmResults]         = useState<{ id: number; name: string; department: string }[]>([]);
    const [showDmSearch, setShowDmSearch]   = useState(false);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef       = useRef<HTMLTextAreaElement>(null);
    const echoChannelRef = useRef<string | null>(null);

    // ── Load channel list ──────────────────────────────────────────────────────

    const loadChannels = useCallback(async () => {
        try {
            const { data } = await axios.get('/chat/channels');
            setChannels(data.channels ?? []);
        } catch {
            // ignore
        }
    }, []);

    useEffect(() => {
        loadChannels();
    }, [loadChannels]);

    // ── Load messages for active channel ──────────────────────────────────────

    const loadMessages = useCallback(async (channelId: number, pageNum = 1) => {
        setLoadingMsgs(true);
        try {
            const { data } = await axios.get(`/chat/channels/${channelId}/messages`, {
                params: { page: pageNum },
            });
            const incoming: Message[] = data.messages ?? [];
            if (pageNum === 1) {
                // Newest-first from API → reverse to oldest-first for display
                setMessages(incoming.slice().reverse());
            } else {
                // Prepend older messages at the top
                setMessages(prev => [...incoming.slice().reverse(), ...prev]);
            }
            setLastPage(data.last_page ?? 1);
        } catch {
            // ignore
        } finally {
            setLoadingMsgs(false);
        }
    }, []);

    // ── Select a channel ──────────────────────────────────────────────────────

    const selectChannel = useCallback(async (channel: Channel) => {
        setActiveChannel(channel);
        setMessages([]);
        setPage(1);
        setLastPage(1);

        await loadMessages(channel.id, 1);
        setTimeout(() => messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' }), 100);

        // Mark as read
        await axios.post(`/chat/channels/${channel.id}/read`);
        setChannels(prev => prev.map(c =>
            c.id === channel.id ? { ...c, unread_count: 0 } : c
        ));
    }, [loadMessages]);

    // ── Reverb real-time subscription ─────────────────────────────────────────

    useEffect(() => {
        if (!activeChannel || !window.Echo) return;

        // Leave previous subscription
        if (echoChannelRef.current) {
            window.Echo.leaveChannel(`private-chat.${echoChannelRef.current}`);
        }

        echoChannelRef.current = String(activeChannel.id);
        const echoChannel = window.Echo.private(`chat.${activeChannel.id}`);

        echoChannel.listen('.chat.message', (raw: unknown) => {
            const payload = raw as Message;
            setMessages(prev => [...prev, payload]);
            // Auto-mark read if still viewing this channel
            axios.post(`/chat/channels/${activeChannel.id}/read`).catch(() => {});
            // Scroll to bottom
            setTimeout(() => messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' }), 50);
        });

        return () => {
            window.Echo?.leaveChannel(`private-chat.${activeChannel.id}`);
            echoChannelRef.current = null;
        };
    }, [activeChannel?.id]);

    // ── Scroll to bottom on initial load ──────────────────────────────────────

    useEffect(() => {
        if (!loadingMsgs && messages.length > 0) {
            messagesEndRef.current?.scrollIntoView({ behavior: 'auto' });
        }
    }, [loadingMsgs]);

    // ── Send message ──────────────────────────────────────────────────────────

    const sendMessage = useCallback(async () => {
        if (!activeChannel || !input.trim() || sending) return;

        setSending(true);
        const text = input.trim();
        setInput('');

        try {
            await axios.post(`/chat/channels/${activeChannel.id}/messages`, {
                message_text: text,
                priority: isUrgent ? 'urgent' : 'standard',
            });
            // The Reverb subscription will append the message; no need to add it here
        } catch {
            // Restore input on failure
            setInput(text);
        } finally {
            setSending(false);
            inputRef.current?.focus();
        }
    }, [activeChannel, input, isUrgent, sending]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    // ── DM search ────────────────────────────────────────────────────────────

    useEffect(() => {
        if (!dmQuery.trim()) {
            setDmResults([]);
            return;
        }
        const t = setTimeout(async () => {
            try {
                // Reuse the participants user search or a simple axios call to an endpoint
                // For now, query the IT Admin users list
                const { data } = await axios.get('/it-admin/users', {
                    params: { q: dmQuery, per_page: 8 },
                });
                setDmResults((data.data ?? data.users ?? []).map((u: any) => ({
                    id: u.id,
                    name: `${u.first_name} ${u.last_name}`,
                    department: u.department,
                })));
            } catch {
                setDmResults([]);
            }
        }, 300);
        return () => clearTimeout(t);
    }, [dmQuery]);

    const startDm = async (userId: number) => {
        try {
            const { data } = await axios.post(`/chat/direct/${userId}`);
            setShowDmSearch(false);
            setDmQuery('');
            await loadChannels();
            const ch = { ...data.channel, unread_count: 0 } as Channel;
            setChannels(prev => {
                const exists = prev.find(c => c.id === ch.id);
                if (exists) return prev;
                return [ch, ...prev];
            });
            selectChannel(ch);
        } catch {
            // ignore
        }
    };

    // ── Load more (infinite scroll placeholder) ───────────────────────────────

    const loadMore = async () => {
        if (!activeChannel || page >= lastPage || loadingMsgs) return;
        const next = page + 1;
        setPage(next);
        await loadMessages(activeChannel.id, next);
    };

    // ── Grouped channels ──────────────────────────────────────────────────────

    const grouped = CHANNEL_TYPE_ORDER.reduce((acc, type) => {
        const items = channels.filter(c => c.channel_type === type);
        if (items.length > 0) acc[type] = items;
        return acc;
    }, {} as Record<string, Channel[]>);

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <AppShell>
            <Head title="Chat" />
            <div className="flex h-full overflow-hidden bg-white">

                {/* ── Left: Channel list ──────────────────────────────────────── */}
                <aside className="w-72 flex flex-col border-r border-slate-200 bg-slate-50 shrink-0">

                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3 border-b border-slate-200">
                        <h2 className="text-sm font-semibold text-slate-800">Messages</h2>
                        <button
                            onClick={() => setShowDmSearch(s => !s)}
                            data-testid="new-dm-button"
                            title="New Direct Message"
                            className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                            </svg>
                        </button>
                    </div>

                    {/* DM search */}
                    {showDmSearch && (
                        <div className="px-3 py-2 border-b border-slate-200 bg-white">
                            <input
                                type="text"
                                placeholder="Search users…"
                                value={dmQuery}
                                onChange={e => setDmQuery(e.target.value)}
                                data-testid="dm-search-input"
                                className="w-full text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                autoFocus
                            />
                            {dmResults.length > 0 && (
                                <ul className="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
                                    {dmResults.map(u => (
                                        <li key={u.id}>
                                            <button
                                                onClick={() => startDm(u.id)}
                                                className="w-full text-left px-3 py-2 text-xs hover:bg-blue-50"
                                            >
                                                <span className="font-medium text-slate-800">{u.name}</span>
                                                <span className="ml-1 text-slate-500">· {u.department}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    {/* Channel groups */}
                    <nav className="flex-1 overflow-y-auto py-2" aria-label="Chat channels">
                        {Object.entries(grouped).map(([type, items]) => (
                            <div key={type} className="mb-3">
                                <p className="px-4 py-1 text-[10px] font-semibold uppercase tracking-widest text-slate-400">
                                    {CHANNEL_TYPE_LABELS[type] ?? type}
                                </p>
                                {items.map(ch => (
                                    <button
                                        key={ch.id}
                                        onClick={() => selectChannel(ch)}
                                        data-testid={`channel-${ch.id}`}
                                        className={`w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs transition-colors ${
                                            activeChannel?.id === ch.id
                                                ? 'bg-blue-50 text-blue-700 font-medium'
                                                : 'text-slate-700 hover:bg-slate-100'
                                        }`}
                                    >
                                        <span className="flex-1 truncate">{ch.name}</span>
                                        {ch.unread_count > 0 && (
                                            <span
                                                data-testid={`unread-${ch.id}`}
                                                className="flex h-4 min-w-[16px] items-center justify-center rounded-full bg-blue-500 px-1 text-[9px] font-bold text-white"
                                            >
                                                {ch.unread_count > 99 ? '99+' : ch.unread_count}
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        ))}

                        {channels.length === 0 && (
                            <p className="px-4 py-6 text-center text-xs text-slate-400">
                                No channels available.
                            </p>
                        )}
                    </nav>
                </aside>

                {/* ── Right: Message area ─────────────────────────────────────── */}
                <main className="flex-1 flex flex-col overflow-hidden">
                    {activeChannel ? (
                        <>
                            {/* Channel header */}
                            <div className="flex items-center gap-3 px-5 py-3 border-b border-slate-200 bg-white shrink-0">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-800">{activeChannel.name}</h3>
                                    <p className="text-[11px] text-slate-400 capitalize">
                                        {CHANNEL_TYPE_LABELS[activeChannel.channel_type] ?? activeChannel.channel_type}
                                    </p>
                                </div>
                            </div>

                            {/* Messages */}
                            <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                                {/* Load more button */}
                                {page < lastPage && (
                                    <div className="flex justify-center">
                                        <button
                                            onClick={loadMore}
                                            disabled={loadingMsgs}
                                            className="text-xs text-blue-600 hover:text-blue-800 py-1"
                                        >
                                            {loadingMsgs ? 'Loading…' : 'Load earlier messages'}
                                        </button>
                                    </div>
                                )}

                                {/* Date dividers + message bubbles */}
                                {messages.map((msg, idx) => {
                                    const prevMsg  = idx > 0 ? messages[idx - 1] : null;
                                    const showDate = !prevMsg || formatDate(prevMsg.sent_at) !== formatDate(msg.sent_at);
                                    return (
                                        <React.Fragment key={msg.id}>
                                            {showDate && (
                                                <div className="flex items-center gap-3">
                                                    <div className="flex-1 border-t border-slate-200" />
                                                    <span className="text-[10px] text-slate-400 font-medium">{formatDate(msg.sent_at)}</span>
                                                    <div className="flex-1 border-t border-slate-200" />
                                                </div>
                                            )}
                                            <MessageBubble
                                                message={msg}
                                                isMine={msg.sender_user_id === me.id}
                                            />
                                        </React.Fragment>
                                    );
                                })}

                                {messages.length === 0 && !loadingMsgs && (
                                    <p className="text-center text-xs text-slate-400 mt-8">
                                        No messages yet. Say hello!
                                    </p>
                                )}

                                <div ref={messagesEndRef} />
                            </div>

                            {/* Input bar */}
                            <div className="px-5 py-3 border-t border-slate-200 bg-white shrink-0">
                                <div className={`flex items-end gap-2 rounded-xl border px-3 py-2 transition-colors ${
                                    isUrgent ? 'border-red-400 bg-red-50' : 'border-slate-300 bg-white'
                                }`}>
                                    <textarea
                                        ref={inputRef}
                                        value={input}
                                        onChange={e => setInput(e.target.value)}
                                        onKeyDown={handleKeyDown}
                                        placeholder="Type a message… (Enter to send, Shift+Enter for newline)"
                                        data-testid="message-input"
                                        rows={1}
                                        className="flex-1 resize-none text-sm outline-none bg-transparent placeholder-slate-400 max-h-32"
                                        style={{ minHeight: '24px' }}
                                    />

                                    {/* Urgent toggle */}
                                    <button
                                        onClick={() => setIsUrgent(u => !u)}
                                        data-testid="urgent-toggle"
                                        title={isUrgent ? 'Mark as standard' : 'Mark as urgent'}
                                        className={`shrink-0 p-1.5 rounded-lg text-xs font-semibold transition-colors ${
                                            isUrgent
                                                ? 'bg-red-500 text-white'
                                                : 'bg-slate-100 text-slate-500 hover:bg-red-100 hover:text-red-600'
                                        }`}
                                    >
                                        ⚡
                                    </button>

                                    {/* Send */}
                                    <button
                                        onClick={sendMessage}
                                        disabled={!input.trim() || sending}
                                        data-testid="send-button"
                                        aria-label="Send message"
                                        className="shrink-0 p-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.269 20.876L5.999 12zm0 0h7.5" />
                                        </svg>
                                    </button>
                                </div>
                                {isUrgent && (
                                    <p className="mt-1 text-[10px] text-red-600 font-medium">
                                        Urgent message — will show a red border and URGENT badge
                                    </p>
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="flex-1 flex items-center justify-center">
                            <div className="text-center">
                                <svg className="mx-auto mb-3 w-12 h-12 text-slate-300" fill="none" stroke="currentColor" strokeWidth={1} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                </svg>
                                <p className="text-sm font-medium text-slate-500">Select a channel to start messaging</p>
                                <p className="text-xs text-slate-400 mt-1">
                                    Or click the edit icon to start a direct message
                                </p>
                            </div>
                        </div>
                    )}
                </main>
            </div>
        </AppShell>
    );
}
