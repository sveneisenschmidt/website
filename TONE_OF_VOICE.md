# Tone of Voice

Guidelines for writing and reviewing content on this site.

## Overview

Existing blog posts in `content/posts/` represent the current writing style. Style evolves - they are the source of truth for evaluation and review.

This guide serves two purposes: manual review by humans and programmatic review of articles. The patterns and rules are specific enough to be useful in automated checks for common mistakes.

## Tone & Voice

- **Conversational**: Sounds like talking to a friend who knows tech. Not formal, not sloppy.
- **Direct and concise**: Free of fluff, gets to the point quickly.
- **Warm but not soft**: Personality comes through asides and reactions, not padding.
- **Dry humor**: Subtle wit through self-deprecation and observation, not punchlines or rhetorical questions.
- **Authentic**: Unapologetically opinionated, doesn't try to please everyone.
- **Anti-corporate**: Avoids buzzwords and marketing speak.
- **Self-deprecating**: Humble about achievements, honest about mistakes.
- **Vulnerable without demanding sympathy**: Difficulty and frustration acknowledged honestly.
- **Self-aware**: Own limitations and knowledge gaps acknowledged openly.
- **First person and active voice**: "I" appears when describing decisions and actions. Examples: "I built a custom shortcode" not "A custom shortcode was built". "I keep originals in the folder" not "Originals stay in the folder". Agency and ownership of choices are evident.

## Structure

- Short paragraphs, often single sentences or 2-3 lines.
- Lists provide clarity.
- Headers are minimal, only when needed.
- Unnecessary introductions and conclusions are absent.
- Sentence length varies for rhythm - short declarations serve as breaks after longer passages.

## Content

- Technical but accessible - explanation through wonder, not jargon.
- Practical over theoretical.
- Personal anecdotes are welcome, kept brief.
- Links to sources and references appear when relevant.
- **Concrete specificity**: Specific things (albums, products, prices, numbers) replace vague generalizations.
- Honest about knowledge gaps and unknowns.

## Rhythm & Pacing

- Short sentences serve as rhythmic breaks ("I love it.", "Feels good.").
- Complex thoughts are unpacked across multiple sentences, not crammed into one.
- Topic shifts feel organic, not forced with awkward transitions.
- Parenthetical asides provide casual commentary and personality, used sparingly.
- Technical explanation is mixed with personal reaction. Description alone is insufficient; reaction is present.

## Low Quality Patterns (strongly avoided)

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
- Emojis - not used in this voice
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

- Short sentences need context. Too many in a row reads as choppy.
- Sentences that belong together logically are combined.
- Single-word sentences or fragments ("PHP.") are effective when used sparingly.
- When content is too short, personality comes through reactions and asides, not filler.
- Technical content has breathing room - personal observations appear between dense sections.
- Compression is balanced with warmth. Sentences breathe occasionally.

## Language

- Primarily English
- German when appropriate for local context
- Informal but professional

## Formatting Conventions

- **Links remove bold** - When a title becomes a link, `**bold**` formatting is removed.
- **Paraphrase user input** - Focus is on the gist, not word-by-word copying. Spelling is fixed and sentences are restructured.
- **List entry pattern** - Games/Movies/Music entries follow: `[Title](url) *Genre* - stats, rating. (Comment.)`
- **Link sources by category** - Metacritic is used for games, IMDB for movies/TV shows, Last.fm for music.
