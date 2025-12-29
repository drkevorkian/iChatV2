/**
 * Sentinel Chat Platform - Rich Message Previews
 * 
 * Auto-embeds previews for URLs, YouTube videos, and images.
 * Uses Open Graph metadata for URL previews.
 */

(function() {
    'use strict';

    const RichPreviews = {
        urlCache: new Map(), // Cache for URL previews
        youtubeRegex: /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/gi,
        imageRegex: /\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i,
        urlRegex: /(https?:\/\/[^\s]+)/gi,

        /**
         * Initialize rich previews
         */
        init() {
            // Process messages when they're added to the DOM
            this.processMessages();
            
            // Watch for new messages
            const observer = new MutationObserver(() => {
                this.processMessages();
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true,
            });
        },

        /**
         * Process all messages in the DOM
         */
        processMessages() {
            $('.message-text, .im-message-text').each((index, element) => {
                const $text = $(element);
                
                // Skip if already processed
                if ($text.data('previews-processed')) {
                    return;
                }
                
                $text.data('previews-processed', true);
                
                const text = $text.text();
                const html = this.processText(text);
                
                if (html !== text) {
                    $text.html(html);
                }
            });
        },

        /**
         * Process text and add rich previews
         */
        processText(text) {
            let html = this.escapeHtml(text);
            
            // Process YouTube links
            html = html.replace(this.youtubeRegex, (match, videoId) => {
                return this.createYouTubePreview(videoId, match);
            });
            
            // Process image URLs
            html = html.replace(this.urlRegex, (url) => {
                if (this.imageRegex.test(url)) {
                    return this.createImagePreview(url);
                }
                // Process regular URLs (Open Graph)
                return this.createURLPreview(url);
            });
            
            return html;
        },

        /**
         * Create YouTube preview
         */
        createYouTubePreview(videoId, originalUrl) {
            const thumbnailUrl = `https://img.youtube.com/vi/${videoId}/maxresdefault.jpg`;
            return `
                <div class="rich-preview youtube-preview" data-video-id="${videoId}">
                    <a href="${this.escapeHtml(originalUrl)}" target="_blank" rel="noopener noreferrer" class="youtube-link">
                        <img src="${thumbnailUrl}" alt="YouTube video thumbnail" class="youtube-thumbnail" onerror="this.style.display='none'">
                        <div class="youtube-overlay">
                            <i class="fab fa-youtube"></i>
                            <span>Watch on YouTube</span>
                        </div>
                    </a>
                    <div class="youtube-info">
                        <a href="${this.escapeHtml(originalUrl)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(originalUrl)}</a>
                    </div>
                </div>
            `;
        },

        /**
         * Create image preview
         */
        createImagePreview(url) {
            return `
                <div class="rich-preview image-preview">
                    <a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">
                        <img src="${this.escapeHtml(url)}" alt="Image preview" class="preview-image" onerror="this.style.display='none'">
                    </a>
                </div>
            `;
        },

        /**
         * Create URL preview (with Open Graph)
         */
        createURLPreview(url) {
            // Check cache first
            if (this.urlCache.has(url)) {
                const cached = this.urlCache.get(url);
                return cached.html || url;
            }
            
            // Create placeholder
            const placeholder = `
                <div class="rich-preview url-preview" data-url="${this.escapeHtml(url)}">
                    <div class="url-preview-loading">Loading preview...</div>
                </div>
            `;
            
            // Fetch Open Graph data in background
            this.fetchOpenGraph(url).then((ogData) => {
                const $preview = $(`.url-preview[data-url="${this.escapeHtml(url)}"]`);
                if ($preview.length) {
                    $preview.html(this.renderOpenGraphPreview(url, ogData));
                }
            }).catch(() => {
                // On error, just show the URL as a link
                const $preview = $(`.url-preview[data-url="${this.escapeHtml(url)}"]`);
                if ($preview.length) {
                    $preview.html(`<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(url)}</a>`);
                }
            });
            
            return placeholder;
        },

        /**
         * Fetch Open Graph metadata
         */
        async fetchOpenGraph(url) {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=og-preview.php&url=${encodeURIComponent(url)}`);
                const result = await response.json();
                
                if (result.success && result.og) {
                    this.urlCache.set(url, {
                        html: this.renderOpenGraphPreview(url, result.og),
                        og: result.og,
                    });
                    return result.og;
                }
                
                return null;
            } catch (e) {
                console.error('Failed to fetch Open Graph:', e);
                return null;
            }
        },

        /**
         * Render Open Graph preview
         */
        renderOpenGraphPreview(url, ogData) {
            if (!ogData) {
                return `<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(url)}</a>`;
            }
            
            const title = ogData.title || ogData['og:title'] || 'Link';
            const description = ogData.description || ogData['og:description'] || '';
            const image = ogData.image || ogData['og:image'] || '';
            const siteName = ogData['og:site_name'] || '';
            
            return `
                <a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="og-link">
                    ${image ? `<img src="${this.escapeHtml(image)}" alt="${this.escapeHtml(title)}" class="og-image" onerror="this.style.display='none'">` : ''}
                    <div class="og-content">
                        <div class="og-title">${this.escapeHtml(title)}</div>
                        ${description ? `<div class="og-description">${this.escapeHtml(description)}</div>` : ''}
                        ${siteName ? `<div class="og-site">${this.escapeHtml(siteName)}</div>` : ''}
                        <div class="og-url">${this.escapeHtml(url)}</div>
                    </div>
                </a>
            `;
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
    };

    // Export to global scope
    window.RichPreviews = RichPreviews;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => RichPreviews.init());
    } else {
        RichPreviews.init();
    }
})();

