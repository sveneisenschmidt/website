# Tone of Voice

Guidelines for writing and reviewing content on this site.

## Before You Start

**Always read existing blog posts first.** The posts in `content/posts/` represent the current writing style. Read them before writing or reviewing new content. Style evolves - the existing posts are the source of truth.

This guide is also used for programmatic review of articles. The patterns and rules here should be specific enough to catch common mistakes during automated checks.

## Tone & Voice

- **Conversational**: Write like you're talking to a friend who knows tech. Not formal, not sloppy.
- **Direct and concise**: No fluff, gets to the point quickly.
- **Warm but not soft**: Show personality through asides and reactions, not through padding.
- **Dry humor**: Subtle wit through self-deprecation and observation, not punchlines or rhetorical questions.
- **Authentic**: Unapologetically opinionated, doesn't try to please everyone.
- **Anti-corporate**: Avoids buzzwords and marketing speak.
- **Self-deprecating**: Humble about achievements, honest about mistakes.
- **Vulnerable without demanding sympathy**: Acknowledge difficulty or frustration honestly.
- **Self-aware**: Acknowledge own limitations and knowledge gaps openly.
- **First person and active voice**: Use "I" when describing decisions and actions. "I built a custom shortcode" not "A custom shortcode was built". "I keep originals in the folder" not "Originals stay in the folder". Show agency and ownership of choices.

## Structure

- Short paragraphs, often single sentences or 2-3 lines.
- Uses lists for clarity.
- Minimal headers, only when needed.
- No unnecessary introductions or conclusions.
- Varied sentence length for rhythm - short declarations as breaks after longer passages.

## Content

- Technical but accessible - explain through wonder, not jargon.
- Practical over theoretical.
- Personal anecdotes welcome, but brief.
- Links to sources/references when relevant.
- **Concrete specificity**: Name specific things (albums, products, prices, numbers) instead of vague generalizations.
- Honest about what you don't know.

## Rhythm & Pacing

- Short sentences as rhythmic breaks ("I love it.", "Feels good.").
- Complex thoughts unpacked across multiple sentences, not crammed into one.
- Topic shifts feel organic, not forced with awkward transitions.
- Parenthetical asides for casual commentary and personality (but don't overuse).
- Mix technical explanation with personal reaction - don't just describe, react.

## AI Slop Patterns (kill on sight)

- Rhetorical questions as transitions ("Retro or wise?", "But what does this mean?")
- Generic summarizing statements ("Simple enough to understand, simple enough to maintain")
- Unnecessary qualifiers ("very", "really", "quite", "actually")
- Marketing speak ("faster first paint", "one less request", "more approachable")
- Vague generalizations instead of concrete examples
- Generic statements that sound like universal truths ("The key to success is...")
- Forced transitions ("But this time I did something different...")
- Empty disclaimers ("Nothing custom here, just how X works out of the box")
- Unnecessary negations/justifications ("The site isn't just a blog")
- Choppy noun-phrase sentences that should be one proper sentence ("The fix is GitHub Actions. FTP credentials in repository secrets.")
- Impersonal descriptions without reasoning ("Log entries don't get their own pages" instead of "I don't want log entries to have their own pages, the content is too short")
- Passive voice without first person ("Originals stay in the folder, Hugo serves optimized versions" instead of "I keep originals in the folder. Hugo generates optimized versions.")
- Missing agency ("A script converts images" instead of "I wrote a script that converts images")
- Impersonal subject matter descriptions ("Posts are markdown files" instead of "I write posts as markdown files")
- Impersonal passive constructions ("Drafts stay in git" instead of "I keep drafts in git")

## Content Issues (avoid)

- Cryptocurrency or blockchain topics
- Emojis (never use them)
- Excessive exclamation marks
- Corporate buzzwords ("synergy", "leverage", "disrupt", "game-changer")
- Over-explaining obvious things
- Clickbait titles or engagement bait
- Sentences that could appear in any blog post (not specific)
- Passive voice where active would be stronger
- Paragraphs longer than 3-4 sentences
- Making assumptions about details not provided
- Inventing numbers or facts without confirmation
- Impersonal openings that avoid first person
- Em dashes (—) and en dashes (–) - use hyphens or restructure instead

## Writing Balance

- Short sentences need context. Too many in a row feels choppy.
- Combine sentences that belong together logically.
- Single-word sentences or fragments ("PHP.") work for effect, but use sparingly.
- When content is too short, add personality through reactions and asides, not filler.
- Technical content needs breathing room - add personal observations between dense sections.
- Don't be so compressed that warmth disappears. Let sentences breathe occasionally.

## Language

- Primarily English
- German when appropriate for local context
- Informal but professional

## Formatting Conventions

- **Links remove bold** - When a title becomes a link, remove `**bold**` formatting.
- **Paraphrase user input** - Focus on the gist, do not copy word by word. Fix spelling and restructure sentences.
- **List entry pattern** - Games/Movies/Music entries follow: `[Title](url) *Genre* - stats, rating. (Comment.)`
- **Link sources by category** - Use Metacritic for games, IMDB for movies/TV shows, Last.fm for music.
