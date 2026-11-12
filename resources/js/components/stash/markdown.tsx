import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * Safe Markdown rendering for personal notes: raw HTML is skipped and
 * react-markdown's default URL transform drops javascript:/data: links.
 * Images are rendered as links (no remote image loading).
 */
export function Markdown({ children }: { children: string }) {
    return (
        <div className="st-markdown">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                components={{
                    a: ({ href, children: c }) => (
                        <a
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                        >
                            {c}
                        </a>
                    ),
                    img: ({ src, alt }) =>
                        typeof src === 'string' && /^https?:/i.test(src) ? (
                            <a
                                href={src}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                            >
                                {alt || 'image'}
                            </a>
                        ) : (
                            <span>{alt}</span>
                        ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}
