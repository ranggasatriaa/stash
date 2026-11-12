export type ResourceType = 'link' | 'image' | 'article';
export type ReadingStatus = 'unread' | 'read';
export type ProcessingStatus =
    | 'none'
    | 'queued'
    | 'processing'
    | 'ready'
    | 'failed';
export type SectionKey =
    | 'all'
    | 'inbox'
    | 'bookmarks'
    | 'screenshots'
    | 'read-later'
    | 'archive'
    | 'trash';
export type SortKey = 'relevance' | 'newest' | 'oldest' | 'title';

export type TagRef = { id: string; name: string; color: string | null };
export type ProjectRef = {
    id: string;
    name: string;
    color: string | null;
    icon: string | null;
};

export type StoredFile = {
    id: string;
    role: 'original' | 'preview' | 'thumbnail';
    url: string;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    download_url?: string;
    checksum_sha256?: string;
};

export type AiRun = {
    id: string;
    resource_id: string | null;
    task: string;
    status: 'queued' | 'running' | 'succeeded' | 'failed';
    provider: string;
    model: string;
    prompt_version: string;
    source_kind: 'article' | 'pasted' | 'metadata';
    input_chars: number;
    cached: boolean;
    result: {
        summary: string;
        key_points: string[];
        imported?: boolean;
    } | null;
    usage: {
        reserved_tokens: number;
        prompt_tokens: number | null;
        completion_tokens: number | null;
        total_tokens: number | null;
        estimated: boolean;
    };
    error: { code: string; message: string } | null;
    created_at: string;
    finished_at: string | null;
    resource_title?: string | null;
};

export type StashResource = {
    id: string;
    type: ResourceType;
    title: string;
    description: string | null;
    url: string | null;
    host: string | null;
    site_name: string | null;
    project: ProjectRef | null;
    project_id: string | null;
    tags: TagRef[];
    read_later: boolean;
    reading_status: ReadingStatus | null;
    read_at: string | null;
    processing: { status: ProcessingStatus; error: string | null };
    archived_at: string | null;
    deleted_at: string | null;
    created_at: string;
    updated_at: string;
    version: number;
    thumbnail: StoredFile | null;
};

export type ResourceDetail = StashResource & {
    personal_notes: string | null;
    manual_fields: string[];
    preview: StoredFile | null;
    original: StoredFile | null;
    source_proposals: { title?: string; description?: string };
    fetched_at: string | null;
    summary: AiRun | null;
    active_run: AiRun | null;
    last_run: AiRun | null;
};

export type Filters = {
    q: string | null;
    type: ResourceType[];
    tags: string[];
    tag_mode: 'any' | 'all';
    project: string | null;
    reading: ReadingStatus | null;
    from: string | null;
    to: string | null;
    sort: SortKey | null;
    sort_effective: SortKey;
    per_page: number;
};

export type PageMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type NavProject = {
    id: string;
    name: string;
    color: string | null;
    icon: string | null;
    description: string | null;
    count: number;
    total: number;
};

export type NavTag = {
    id: string;
    name: string;
    color: string | null;
    count: number;
    total: number;
};

export type SavedViewRef = {
    id: string;
    name: string;
    section: SectionKey;
    filters: Partial<Filters>;
    sort: SortKey;
};

export type NavData = {
    counts: Record<SectionKey, number>;
    projects: NavProject[];
    tags: NavTag[];
    saved_views: SavedViewRef[];
};

export type Scope =
    | {
          type: 'project';
          id: string;
          name: string;
          description: string | null;
          color: string | null;
      }
    | { type: 'tag'; id: string; name: string; color: string | null }
    | null;

export type Duplicate = {
    id: string;
    title: string;
    type: ResourceType;
    created_at: string;
    archived: boolean;
    trashed: boolean;
};

export type ToastPayload = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    undo?: {
        method: 'post' | 'delete' | 'patch';
        url: string;
        data?: Record<string, unknown>;
        label?: string;
    } | null;
};

export type StashUser = {
    id: number;
    name: string;
    email: string;
    timezone: string;
    preferences: Record<string, unknown>;
    two_factor_enabled: boolean;
};
