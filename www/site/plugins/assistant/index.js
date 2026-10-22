// The Vue components of the Panel area "Assistent" and its pages "Protokoll"
// and "Einstellungen". Kirby loads this file as it is, without a build step,
// like the plugins of "Verlauf" and "Besucher". The browser drives the chat
// one round at a time (src/Assistant/Chat.php) and uploads one file per
// request.

function escape(text) {
  return String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}
function unescape(text) {
  return text.replace(/&(amp|lt|gt|quot|#39);/g, (all, name) => ({ amp: "&", lt: "<", gt: ">", quot: '"', "#39": "'" })[name]);
}
function hostOf(url) {
  try {
    return new URL(url).host.replace(/^www\./, "");
  } catch (e) {
    return "";
  }
}
// Renders a small Markdown subset of Claude's answers. The text is escaped
// first, so an answer cannot add its own HTML. Only links from the web search
// of this chat become clickable, each with its host next to it; any other
// link stays text.
function inline(text, sources) {
  return escape(text)
    .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
    .replace(/(^|[^*])\*([^*\s][^*]*?)\*(?!\*)/g, "$1<em>$2</em>")
    .replace(/`([^`]+)`/g, "<code>$1</code>")
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (all, label, url) => {
      const host = '<span class="blog-ai-host">' + escape(hostOf(unescape(url))) + "</span>";
      return sources.includes(unescape(url))
        ? '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + "</a> " + host
        : label + " " + host;
    });
}
function markdown(text, sources) {
  const html = [];
  for (const block of text.trim().split(/\n{2,}/)) {
    // Consecutive list lines become one list; other lines become a paragraph.
    let run = null;
    const flush = () => {
      if (!run) return;
      const tag = run.kind;
      html.push(tag === "p" ? "<p>" + run.lines.map((l) => inline(l, sources)).join("<br>") + "</p>"
        : "<" + tag + ">" + run.lines.map((l) => "<li>" + inline(l, sources) + "</li>").join("") + "</" + tag + ">");
      run = null;
    };
    for (const line of block.split("\n")) {
      const ul = line.match(/^\s*[-*] (.*)$/);
      const ol = line.match(/^\s*\d+\. (.*)$/);
      const kind = ul ? "ul" : ol ? "ol" : "p";
      if (!run || run.kind !== kind) {
        flush();
        run = { kind, lines: [] };
      }
      run.lines.push(ul ? ul[1] : ol ? ol[1] : line);
    }
    flush();
  }
  return html.join("");
}
// Shows a text on a card as plain text and highlights its links. The server
// renders each text as the site does and returns its links (`Marks::of()`
// in src/Assistant/Marks.php). Exactly these strings are highlighted,
// wherever they occur in the text.
function marked(text, links) {
  const found = [...new Set(links || [])].filter((link) => link !== "").sort((a, b) => b.length - a.length);
  if (found.length === 0) return escape(text);
  const pattern = new RegExp(found.map((link) => link.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|"), "g");
  let html = "";
  let last = 0;
  for (const match of text.matchAll(pattern)) {
    html += escape(text.slice(last, match.index)) + '<mark class="blog-ai-link">' + escape(match[0]) + "</mark>";
    last = match.index + match[0].length;
  }
  return html + escape(text.slice(last));
}

// The help text "So arbeitet der Assistent". It shows beside the chat; in a
// narrow window the info button opens it in a dialog (index.css).
const ABOUT = `
  <h2>So arbeitet der Assistent</h2>
  <p>Er schlägt vor, du entscheidest. Ohne deinen Klick ändert sich nichts.</p>
  <div><h3>Darf</h3><ul>
    <li>Entwürfe anlegen, mit Fotos und Route</li>
    <li>Passagen, Felder und Bildtexte ändern</li>
    <li>Routen setzen, aus Orten oder einer GPX-Datei</li>
    <li>Im Web nachschlagen, immer mit Quelle</li>
  </ul></div>
  <div><h3>Darf nicht</h3><ul>
    <li>Löschen</li><li>Veröffentlichen</li><li>Kameradaten schreiben</li>
  </ul></div>
  <p class="blog-ai-note">Bei veröffentlichten Beiträgen und About schreibt ein Klick in die Änderungen. Live geht es erst mit deinem Speichern. Was du schickst, auch Fotos, geht an Anthropic. Orte einer Route gehen an OSRM. Von einer GPX-Datei sieht Claude nur die Linie ohne ihre Enden, gut 1 km um Start und Ziel. Ein Halt unterwegs bleibt sichtbar.</p>
`;

panel.plugin("website/assistant", {
  icons: {
    robot: '<g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="7.5" width="15" height="12" rx="3"/><path d="M12 7.5V4.5M9.3 19.5v1.8M14.7 19.5v1.8M2.3 12.8v3M21.7 12.8v3M9.5 16.5h5"/><circle cx="12" cy="3.5" r=".9"/></g><circle cx="9" cy="12.9" r="1.4"/><circle cx="15" cy="12.9" r="1.4"/>',
  },
  components: {
    "k-blog-assistant-view": {
      props: {
        items: Array,
        next: Boolean,
        long: Boolean,
        open: Number,
        hasKey: Boolean,
        hasPrompt: Boolean,
        pending: Array,
        sources: Array,
        page: Object,
        settings: String,
      },
      data() {
        return {
          chat: { items: this.items, long: this.long, open: this.open, pending: this.pending, sources: this.sources, hasKey: this.hasKey, hasPrompt: this.hasPrompt },
          context: this.page,
          text: "",
          busy: false,
          uploading: null,
          dragging: false,
          error: null,
          accessError: false,
        };
      },
      computed: {
        about() {
          return ABOUT;
        },
        // The assistant is off without a key, after Anthropic refused the key,
        // or without a prompt.
        off() {
          return !this.chat.hasKey || !this.chat.hasPrompt || this.accessError;
        },
        offText() {
          return this.chat.hasPrompt
            ? "Der Schlüssel für Anthropic fehlt oder wird abgelehnt. Er steht als assistantKey in env.php."
            : "Der Prompt unter Assistent › Einstellungen ist leer. Ohne Prompt ist der Assistent aus.";
        },
      },
      mounted() {
        document.addEventListener("click", this.guardLinks, true);
        window.addEventListener("beforeunload", this.guardUnload);
        window.addEventListener("resize", this.fit);
        this.fit();
        // A chat that was left during a tool loop continues with the next round.
        if (this.next) {
          this.loop();
        }
        // Opened with the button of a page while a chat exists: offer a new chat.
        if (this.page && this.chat.items.length > 0) {
          this.chat.items.push({ type: "offer" });
        }
        this.scroll();
      },
      destroyed() {
        document.removeEventListener("click", this.guardLinks, true);
        window.removeEventListener("beforeunload", this.guardUnload);
        window.removeEventListener("resize", this.fit);
      },
      methods: {
        apply(state) {
          const grew = state.items.length > this.chat.items.length;
          this.chat = { items: state.items, long: state.long, open: state.open, pending: state.pending, sources: state.sources, hasKey: state.hasKey, hasPrompt: state.hasPrompt };
          // Scroll only when items were added, so a click on an older card
          // keeps the scroll position.
          if (grew) this.scroll();
          return state;
        },
        async post(path, data = {}) {
          return this.apply(await this.$api.post("blog-assistant/" + path, data));
        },
        // The chat fills the window below the header; the input stays in view.
        fit() {
          this.$nextTick(() => {
            const body = this.$el.querySelector(".blog-ai-body");
            if (!body) return;
            const top = body.getBoundingClientRect().top + window.scrollY;
            body.style.height = Math.max(384, window.innerHeight - top - 16) + "px";
          });
        },
        // The thread is a reversed column: scrollTop 0 is the newest message.
        scroll() {
          this.$nextTick(() => {
            if (this.$refs.scroll) this.$refs.scroll.scrollTop = 0;
          });
        },
        info() {
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: { text: ABOUT, cancelButton: false, submitButton: "Schließen" },
          });
        },
        // choice is the index of an option in the open choice. Only the index
        // goes to the server, which takes the option text from the stored
        // chat (`Chat::choose()` in src/Assistant/Chat.php).
        async send(text = this.text, choice = null) {
          text = text.trim();
          if (text === "" || this.busy || this.uploading) return;
          this.error = null;
          this.busy = true;
          if (choice === null) this.text = "";
          // The message shows at once, before the first round answers.
          this.chat.items.push({ type: "me", text, ts: Math.floor(Date.now() / 1000), files: this.chat.pending.map((file) => file.name) });
          this.grow();
          this.scroll();
          try {
            const state = await this.post("send", choice === null ? { text, page: this.context?.id ?? null } : { choice, page: this.context?.id ?? null });
            if (state.error) {
              this.error = state.error;
              this.accessError = state.error.kind === "access";
              if (choice === null) this.text = text;
              return;
            }
            // The page from the button "Assistent" goes with one message only.
            this.context = null;
            if (state.next) {
              await this.loop();
            }
          } catch (e) {
            if (choice === null) this.text = text;
            this.$panel.error(e);
          } finally {
            this.busy = false;
          }
        },
        async loop() {
          this.busy = true;
          try {
            let state = { next: true };
            while (state.next && this.busy) {
              state = await this.post("next");
              // The server adds the error of a later round to the chat items;
              // the message stays sent.
              if (state.error) {
                this.accessError = state.error.kind === "access";
                break;
              }
            }
          } catch (e) {
            this.$panel.error(e);
          } finally {
            this.busy = false;
          }
        },
        // Uploads picked or dropped files one after the other, one request per file.
        pick(event) {
          this.upload([...event.target.files]);
          event.target.value = "";
        },
        drop(event) {
          this.dragging = false;
          if (!this.off && !this.busy) this.upload([...event.dataTransfer.files]);
        },
        async upload(files) {
          for (const file of files) {
            this.uploading = file.name;
            const form = new FormData();
            form.append("file", file);
            try {
              const response = await fetch(this.$panel.urls.api + "/blog-assistant/upload", {
                method: "POST",
                headers: { "X-CSRF": this.$panel.system.csrf },
                body: form,
              });
              const state = await response.json();
              if (!response.ok) throw new Error(state.message || file.name + ": nicht hochgeladen");
              this.apply(state);
              this.error = state.error ?? null;
              if (state.error) break;
            } catch (e) {
              this.$panel.error(e);
              break;
            }
          }
          this.uploading = null;
        },
        // Progress lines show under the assistant's name when its answer follows.
        before(index, type) {
          return index > 0 && this.chat.items[index - 1].type === type;
        },
        after(index, type) {
          const next = this.chat.items[index + 1];
          return next !== undefined && (next.type === type || next.type === "error");
        },
        // A choice is open until the Panel user sends the next message.
        answered(index) {
          return this.chat.items.slice(index + 1).some((item) => item.type === "me");
        },
        async stop() {
          this.busy = false;
          await this.post("stop");
        },
        async decide(id, action, version = null) {
          // A card that is gone, for example after "Neuer Chat" in another tab,
          // comes back with the key error.
          const state = await this.post("decide", { id, action, version });
          this.error = state.error ?? null;
        },
        reset() {
          if (this.chat.open === 0) {
            return this.post("reset");
          }
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text: "<p><strong>Neuen Chat beginnen?</strong></p><p>Im Chat warten noch Vorschläge auf dich. Sie gehen verloren, wenn du neu beginnst, mit ihren Uploads.</p>",
              submitButton: { text: "Neu beginnen", icon: "refresh", theme: "passive" },
              cancelButton: "Zurück zum Chat",
            },
            on: {
              submit: async () => {
                this.$panel.dialog.close();
                await this.post("reset");
              },
            },
          });
        },
        md(text) {
          return markdown(text, this.chat.sources);
        },
        marked(text, links) {
          return marked(text, links);
        },
        host(url) {
          return hostOf(url);
        },
        names(files) {
          return files.map((file) => file.name).join(", ");
        },
        // The time shows in the timezone of the browser.
        time(ts) {
          if (!ts) return "";
          const d = new Date(ts * 1000);
          return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
        },
        // The input grows with the text, up to its maximum height.
        grow() {
          this.$nextTick(() => {
            const input = this.$refs.input;
            if (!input) return;
            input.style.height = "auto";
            input.style.height = input.scrollHeight + 2 + "px";
          });
        },
        // Enter sends, Shift+Enter starts a new line.
        keydown(event) {
          if (event.isComposing || event.key !== "Enter" || event.shiftKey) return;
          event.preventDefault();
          this.send();
        },
        // While the assistant works, a click on a Panel link asks before the
        // page is left.
        guardLinks(event) {
          if (!this.busy) return;
          const link = event.target.closest("a[href]");
          if (!link || link.target === "_blank") return;
          event.preventDefault();
          event.stopPropagation();
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text: "<p><strong>Seite verlassen?</strong></p><p>Der Assistent arbeitet noch. Wenn du gehst, ist seine Antwort weg. Geändert wird nichts.</p>",
              submitButton: { text: "Verlassen", icon: "logout", theme: "passive" },
              cancelButton: "Bleiben",
            },
            on: {
              submit: () => {
                this.busy = false;
                this.$panel.dialog.close();
                this.$api.post("blog-assistant/stop");
                this.$panel.view.open(link.getAttribute("href"));
              },
            },
          });
        },
        // For a reload or a closed tab, browsers allow only their own
        // confirmation dialog.
        guardUnload(event) {
          if (this.busy || this.uploading) {
            event.preventDefault();
            event.returnValue = "";
          }
        },
      },
      template: `
        <k-panel-inside class="k-blog-assistant-view">
          <k-header>
            Assistent
            <template #buttons>
              <k-button icon="info" size="sm" variant="filled" class="blog-ai-info" title="So arbeitet der Assistent" @click="info" />
              <k-button icon="add" size="sm" variant="filled" @click="reset">Neuer Chat</k-button>
              <k-button icon="list-bullet" size="sm" variant="filled" link="assistent/protokoll">Protokoll</k-button>
              <k-button icon="cog" size="sm" variant="filled" :link="settings">Einstellungen</k-button>
            </template>
          </k-header>
          <div class="blog-ai-body">
            <section class="blog-ai-panel" :class="{ 'blog-ai-drop': dragging }" aria-label="Chat"
              @dragover.prevent="dragging = !off" @dragleave="dragging = false" @drop.prevent="drop">
              <div ref="scroll" class="blog-ai-scroll">
                <div class="blog-ai-thread" role="log" aria-live="polite">
                  <p v-if="chat.items.length === 0 && !busy" class="blog-ai-empty-chat">Sag mir, was ich vorschlagen soll. Ich schlage vor, du entscheidest.</p>
                  <template v-for="(item, index) in chat.items">
                    <div v-if="item.type === 'me'" :key="index" class="blog-ai-me">
                      <p class="blog-ai-who">Du <span v-if="item.ts" class="blog-ai-time">{{ time(item.ts) }}</span></p>
                      <p class="blog-ai-bubble">{{ item.text }}</p>
                      <p v-if="item.files && item.files.length" class="blog-ai-who">Angehängt: {{ item.files.join(", ") }}</p>
                    </div>
                    <div v-else-if="item.type === 'ai' || item.type === 'error'" :key="index" class="blog-ai-ai">
                      <span class="blog-ai-bot" aria-hidden="true"><k-icon type="robot" /></span>
                      <p class="blog-ai-who">Assistent <span v-if="item.ts" class="blog-ai-time">{{ time(item.ts) }}</span></p>
                      <div v-if="before(index, 'progress')" class="blog-ai-progress">
                        <p v-for="line in chat.items[index - 1].lines" :key="line"><k-icon :type="line === 'Im Web gesucht' ? 'globe' : 'check'" />{{ line }}</p>
                      </div>
                      <div class="blog-ai-say" v-html="md(item.text)"></div>
                    </div>
                    <p v-else-if="item.type === 'sources'" :key="index" class="blog-ai-indent blog-ai-sources">
                      Quelle<template v-if="item.list.length > 1">n</template>:
                      <template v-for="(source, i) in item.list"><a :key="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.title }}</a> <span :key="source.url + 'h'" class="blog-ai-host">{{ host(source.url) }}</span><template v-if="i < item.list.length - 1"> · </template></template>
                    </p>
                    <div v-else-if="item.type === 'progress' && !after(index, 'ai')" :key="index" class="blog-ai-indent blog-ai-progress">
                      <p v-for="line in item.lines" :key="line"><k-icon :type="line === 'Im Web gesucht' ? 'globe' : 'check'" />{{ line }}</p>
                    </div>
                    <div v-else-if="item.type === 'note'" :key="index" class="blog-ai-indent blog-ai-note">{{ item.text }}</div>
                    <div v-else-if="item.type === 'offer'" :key="index" class="blog-ai-indent blog-ai-hint">
                      Du hast den Assistenten aus „{{ page.title }}“ geöffnet. Dafür lieber neu beginnen?
                      <k-button icon="add" size="sm" variant="filled" @click="reset">Neuer Chat</k-button>
                    </div>
                    <div v-else-if="item.type === 'choice'" :key="index" class="blog-ai-ai">
                      <span class="blog-ai-bot" aria-hidden="true"><k-icon type="robot" /></span>
                      <p class="blog-ai-who">Assistent</p>
                      <p class="blog-ai-say">{{ item.question }}</p>
                      <div class="blog-ai-choice">
                        <k-button v-for="(option, o) in item.options" :key="option" size="sm" variant="filled" :disabled="busy || answered(index)" @click="send(option, o)">{{ option }}</k-button>
                      </div>
                    </div>
                    <section v-else-if="item.type === 'card' && item.proposal" :key="index" class="blog-ai-indent blog-ai-card" :aria-label="'Vorschlag: ' + item.proposal.title">
                      <header class="blog-ai-card-head">
                        <span>{{ item.proposal.title }}</span>
                        <span v-if="item.proposal.kind === 'post'" class="blog-ai-chip"><span class="blog-ai-dot"></span>Entwurf</span>
                      </header>
                      <dl v-if="item.proposal.rows" class="blog-ai-rows">
                        <template v-for="row in item.proposal.rows">
                          <dt :key="row.label + 't'">{{ row.label }}</dt>
                          <dd :key="row.label + 'd'" :class="{ 'blog-ai-empty': !row.value }" v-html="row.value ? marked(row.value, row.marks) : 'leer'"></dd>
                        </template>
                      </dl>
                      <ul v-if="item.proposal.photos && item.proposal.photos.length" class="blog-ai-photos">
                        <li v-for="photo in item.proposal.photos" :key="photo.filename">
                          <img v-if="photo.thumb" :src="photo.thumb" alt="" />
                          <dl class="blog-ai-rows">
                            <dt>Datei</dt><dd>{{ photo.filename }}</dd>
                            <dt>Alternativtext</dt><dd :class="{ 'blog-ai-empty': !photo.alt }">{{ photo.alt || 'leer' }}</dd>
                            <dt>Bildunterschrift</dt><dd :class="{ 'blog-ai-empty': !photo.caption }" v-html="photo.caption ? marked(photo.caption, photo.marks) : 'leer'"></dd>
                            <dt>Fotografiert von</dt><dd :class="{ 'blog-ai-empty': !photo.photographer }">{{ photo.photographer || 'leer' }}</dd>
                          </dl>
                        </li>
                      </ul>
                      <template v-for="(row, r) in item.proposal.diff || []">
                        <p :key="'f' + r" class="blog-ai-field">{{ row.label }}</p>
                        <div :key="'o' + r" class="blog-ai-diff blog-ai-old">
                          <span>{{ item.proposal.unsaved ? 'Vorher, nicht gespeichert' : 'Vorher' }}</span>
                          <p :class="{ 'blog-ai-empty': !row.before }" v-html="row.before ? marked(row.before, row.marksBefore) : 'leer'"></p>
                        </div>
                        <div :key="'n' + r" class="blog-ai-diff blog-ai-new">
                          <span>Nachher</span>
                          <p :class="{ 'blog-ai-empty': !row.after }" v-html="row.after ? marked(row.after, row.marksAfter) : 'leer'"></p>
                        </div>
                      </template>
                      <template v-if="item.proposal.kind === 'edit'">
                        <p class="blog-ai-field">{{ item.proposal.field }}</p>
                        <div class="blog-ai-diff blog-ai-old">
                          <span>{{ item.proposal.unsaved ? 'Vorher, nicht gespeichert' : 'Vorher' }}</span>
                          <p v-html="marked(item.proposal.passage, item.proposal.passageMarks)"></p>
                        </div>
                        <div v-for="(version, v) in item.proposal.versions" :key="'v' + v" class="blog-ai-diff blog-ai-new">
                          <span>{{ item.proposal.versions.length > 1 ? 'Fassung ' + (v + 1) : 'Nachher' }}</span>
                          <div class="blog-ai-version">
                            <p v-html="marked(version, (item.proposal.versionMarks || [])[v])"></p>
                            <k-button v-if="item.proposal.versions.length > 1 && ['open', 'failed'].includes(item.proposal.state)" size="sm" variant="filled" theme="positive" :disabled="busy" @click="decide(item.id, 'apply', v)">Diese Fassung übernehmen</k-button>
                          </div>
                        </div>
                      </template>
                      <p v-if="item.proposal.state === 'open' && item.proposal.unsaved" class="blog-ai-warn">In diesem Feld gibt es ungespeicherte Änderungen. Übernehmen ersetzt sie.</p>
                      <p v-if="item.proposal.state === 'stale'" class="blog-ai-warn">{{ item.proposal.warn }}</p>
                      <p v-if="item.proposal.state === 'failed'" class="blog-ai-warn">Das hat nicht geklappt. Nichts geändert.</p>
                      <footer class="blog-ai-card-foot">
                        <template v-if="['open', 'failed'].includes(item.proposal.state)">
                          <k-button v-if="!(item.proposal.versions && item.proposal.versions.length > 1)" size="sm" variant="filled" theme="positive" :disabled="busy" @click="decide(item.id, 'apply')">{{ item.proposal.state === 'failed' ? 'Nochmal versuchen' : (item.proposal.kind === 'post' ? 'Anlegen' : 'Übernehmen') }}</k-button>
                          <k-button size="sm" variant="filled" :disabled="busy" @click="decide(item.id, 'discard')">Verwerfen</k-button>
                          <span class="blog-ai-note">{{ item.proposal.where }}</span>
                        </template>
                        <template v-else-if="item.proposal.state === 'done'">
                          <span class="blog-ai-done"><k-icon type="check" />{{ item.proposal.result.text }}</span>
                          <k-link v-if="item.proposal.result.link" :to="item.proposal.result.link" target="_blank">{{ item.proposal.result.linkText }}</k-link>
                        </template>
                        <span v-else-if="item.proposal.state === 'discarded'" class="blog-ai-note">Verworfen.</span>
                      </footer>
                    </section>
                  </template>

                  <div v-if="busy" class="blog-ai-ai">
                    <span class="blog-ai-bot" aria-hidden="true"><k-icon type="robot" /></span>
                    <p class="blog-ai-who">Assistent</p>
                    <div class="blog-ai-progress">
                      <p><k-icon type="loader" />Vorschlag wird vorbereitet</p>
                      <k-button size="sm" variant="filled" @click="stop">Abbrechen</k-button>
                    </div>
                  </div>
                  <div v-if="error && !busy" class="blog-ai-ai">
                    <span class="blog-ai-bot" aria-hidden="true"><k-icon type="robot" /></span>
                    <p class="blog-ai-who">Assistent</p>
                    <p class="blog-ai-say">{{ error.text }}</p>
                    <div v-if="error.kind === 'busy' && text" class="blog-ai-choice">
                      <k-button size="sm" variant="filled" theme="positive" @click="send()">Nochmal senden</k-button>
                    </div>
                  </div>
                  <p v-if="chat.long" class="blog-ai-hint">
                    Dieser Chat ist lang. Ein neuer Chat ist schneller und kostet weniger. Der alte Chat ist dann weg.
                    <k-button icon="add" size="sm" variant="filled" @click="reset">Neuer Chat</k-button>
                  </p>
                </div>
              </div>

              <form class="blog-ai-dock" @submit.prevent="send()">
                <div v-if="context" class="blog-ai-context">
                  <span class="blog-ai-ctx">Seite: {{ context.title }}<button type="button" aria-label="Seite entfernen" @click="context = null"><k-icon type="cancel-small" /></button></span>
                </div>
                <p v-if="chat.pending.length || uploading" class="blog-ai-note">
                  <template v-if="chat.pending.length">Angehängt: {{ names(chat.pending) }}</template>
                  <template v-if="uploading"> Lädt hoch: {{ uploading }}</template>
                </p>
                <div class="blog-ai-inrow">
                  <input ref="files" type="file" multiple accept="image/jpeg,image/png,image/webp,.gpx" class="blog-ai-sr" tabindex="-1" aria-hidden="true" @change="pick" />
                  <k-button icon="attachment" variant="filled" class="blog-ai-attach" :disabled="off || busy || !!uploading" title="Fotos oder GPX anhängen" @click="$refs.files.click()" />
                  <label class="blog-ai-sr" for="blog-ai-input">Nachricht an den Assistenten</label>
                  <textarea id="blog-ai-input" ref="input" v-model="text" rows="2" :disabled="off || busy"
                    :placeholder="off ? 'Der Assistent ist aus.' : 'Was soll ich vorschlagen? Fotos und GPX-Dateien kannst du hierher ziehen.'"
                    @keydown="keydown" @input="grow"></textarea>
                  <k-button type="submit" icon="plane" variant="filled" class="blog-ai-send" :disabled="off || busy || !!uploading" title="Senden"><span class="blog-ai-send-text">Senden</span></k-button>
                </div>
                <p v-if="off" class="blog-ai-note">{{ offText }} <k-link :to="settings" target="_blank">Zu den Einstellungen</k-link></p>
              </form>
            </section>
            <aside class="blog-ai-aside" aria-label="So arbeitet der Assistent" v-html="about"></aside>
          </div>
        </k-panel-inside>
      `,
    },
    // The page "Protokoll": one row per answer, without the text of any message.
    "k-blog-assistant-log-view": {
      props: { rows: Array, totals: Object },
      methods: {
        when(time) {
          return new Date(time).toLocaleString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
        },
        number(value, digits = 0) {
          return Number(value).toLocaleString("de-DE", { minimumFractionDigits: digits, maximumFractionDigits: digits });
        },
        tools(tools) {
          return Object.entries(tools).map(([name, count]) => (count > 1 ? name + " × " + count : name)).join(", ");
        },
        outcome(outcome) {
          return { ok: "Fertig", stopped: "Abgebrochen" }[outcome] || "Fehler: " + outcome;
        },
      },
      template: `
        <k-panel-inside class="k-blog-assistant-log-view">
          <k-header>
            Protokoll
            <template #buttons>
              <k-button icon="robot" size="sm" variant="filled" link="assistent">Zum Assistenten</k-button>
            </template>
          </k-header>
          <k-text class="blog-ai-log-intro">
            <p>{{ totals.chats }} Chats · {{ totals.answers }} Antworten · im Schnitt {{ number(totals.seconds, 1) }} s pro Antwort · {{ totals.accepted }} Vorschläge angenommen · {{ totals.discarded }} verworfen.</p>
            <p>Das Protokoll speichert keine Inhalte: nur wer, wann, wie lange, welche Werkzeuge und wie viele Tokens.</p>
          </k-text>
          <k-empty v-if="rows.length === 0" icon="robot">Noch keine Antworten.</k-empty>
          <div v-else class="blog-ai-log-wrap">
            <table class="blog-ai-log">
              <thead>
                <tr><th>Zeit</th><th>Account</th><th>Chat · Nachricht</th><th>Dauer</th><th>Runden</th><th>Werkzeuge</th><th>Tokens ein / aus</th><th>Aus dem Cache</th><th>Modell</th><th>Ergebnis</th></tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in rows" :key="i">
                  <td class="blog-ai-log-mono">{{ when(row.time) }}</td>
                  <td>{{ row.name }}</td>
                  <td class="blog-ai-log-mono">{{ row.chat }}</td>
                  <td class="blog-ai-log-mono">{{ number(row.seconds, 1) }} s</td>
                  <td class="blog-ai-log-mono">{{ row.rounds }}</td>
                  <td>{{ tools(row.tools) || "keine" }}</td>
                  <td class="blog-ai-log-mono">{{ number(row.tokensIn) }} / {{ number(row.tokensOut) }}</td>
                  <td class="blog-ai-log-mono">{{ row.cached === null ? "" : row.cached + " %" }}</td>
                  <td>{{ row.model }}</td>
                  <td>{{ outcome(row.outcome) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </k-panel-inside>
      `,
    },
    // The page "Einstellungen": the prompt on the left, the model and the
    // access to Anthropic on the right. "Speichern" and Cmd+S write the
    // fields to the site through Kirby's API (PATCH site). "Verbindung
    // prüfen" checks that Claude answers with the key.
    "k-blog-assistant-settings-view": {
      props: { fields: Object, value: Object, hasKey: Boolean },
      data() {
        return { values: { ...this.value }, saving: false, result: null, checking: false };
      },
      created() {
        this.$events.on("view.save", this.onViewSave);
      },
      destroyed() {
        this.$events.off("view.save", this.onViewSave);
      },
      methods: {
        onViewSave(event) {
          event?.preventDefault?.();
          this.save();
        },
        async save() {
          this.saving = true;
          try {
            await this.$api.patch("site", this.values);
            // An unsaved draft of the view "Website" holds the old values.
            // It gets the new ones, so that its save does not bring the old
            // values back. Without a draft Kirby keeps none.
            await this.$api.post("site/changes/save", this.values);
            this.$panel.notification.success("Gespeichert");
          } catch (error) {
            // The fields keep what was typed.
            this.$panel.notification.error(error);
          } finally {
            this.saving = false;
          }
        },
        async check() {
          this.checking = true;
          this.result = await this.$api.post("blog-assistant/check");
          this.checking = false;
        },
      },
      template: `
        <k-panel-inside class="k-blog-assistant-settings-view">
          <k-header>
            Einstellungen
            <template #buttons>
              <k-button icon="check" variant="filled" :disabled="saving" @click="save">Speichern</k-button>
            </template>
          </k-header>
          <k-grid variant="columns">
            <k-column width="2/3">
              <k-fieldset :fields="{ assistantprompt: fields.assistantprompt }" :value="values" @input="values = $event" />
            </k-column>
            <k-column width="1/3">
              <k-fieldset :fields="{ assistantmodel: fields.assistantmodel }" :value="values" @input="values = $event" />
              <section class="k-section blog-ai-access">
                <k-label type="section">Zugang zu Anthropic</k-label>
                <div class="blog-ai-key">
                  <span class="blog-ai-stat"><span :class="hasKey ? 'blog-ai-ok' : 'blog-ai-no'"></span>{{ hasKey ? 'Eingerichtet' : 'Fehlt' }}</span>
                  <k-button v-if="hasKey" size="sm" variant="filled" :disabled="checking" @click="check">Verbindung prüfen</k-button>
                </div>
                <p v-if="result" class="blog-ai-stat blog-ai-result"><k-icon :type="result.ok ? 'check' : 'alert'" />{{ result.text }}</p>
                <p class="blog-ai-help">Der Schlüssel steht als assistantKey in env.php, nicht im Panel. Ohne ihn ist der Assistent aus.</p>
                <p class="blog-ai-help">Was du dem Assistenten schickst, auch Fotos, geht an Anthropic.</p>
              </section>
            </k-column>
          </k-grid>
        </k-panel-inside>
      `,
    },
  },
});
