export type Project = {
    id: number;
    name: string;
    system_prompt: string | null;
};

export type Conversation = {
    id: string;
    title: string;
    project_id: number | null;
    parent_id: string | null;
    is_pinned: boolean;
    updated_at: string;
};

export type ToolCall = {
    tool_id: string;
    tool_name: string;
    arguments: Record<string, unknown>;
    result?: string;
    successful?: boolean;
    error?: string | null;
};

export type Attachment = {
    name: string;
    type: string;
    url?: string;
    path?: string;
    size?: number;
};

export type Usage = {
    prompt_tokens: number;
    completion_tokens: number;
    cache_write_input_tokens?: number;
    cache_read_input_tokens?: number;
    reasoning_tokens?: number;
};

/*
 * A single stage in a multi-agent workflow's lifecycle (Researcher →
 * Editor → Critic for the research pipeline). The reducer upserts
 * phases on the active assistant message as `phase` SSE frames
 * arrive, and the persisted copy rides on ConversationMessage.meta
 * so a reload still shows what happened.
 */
export type Phase = {
    key: string;
    label: string;
    status: 'running' | 'complete' | 'failed';
    approved?: boolean;
    confidence?: 'low' | 'medium' | 'high';
    issues?: string[];
    missing_topics?: string[];
};

export type Message = {
    id: string | number;
    role: 'user' | 'assistant';
    content: string;
    toolCalls?: ToolCall[];
    attachments?: Attachment[];
    status?: string;
    error?: boolean;
    model?: string;
    usage?: Usage | null;
    cost?: number | null;
    variants?: MessageVariant[];
    phases?: Phase[];
    created_at?: string;
};

/*
 * A prior version of an assistant message that the user can flip back to
 * via the carousel. Lacks `variants`/`status`/`error` because only the
 * active top-level slot streams or errors.
 */
export type MessageVariant = {
    id: string;
    role: 'assistant';
    content: string;
    toolCalls?: ToolCall[];
    attachments?: Attachment[];
    model?: string;
    usage?: Usage | null;
    cost?: number | null;
    phases?: Phase[];
    created_at?: string;
};
