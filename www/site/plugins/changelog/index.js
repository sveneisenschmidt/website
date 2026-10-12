// Panel view "Verlauf": the change log, newest first. No build step:
// Kirby ships the Vue template compiler (panel.vue.compiler, on by default).
const actions = {
  "page.create": "Seite angelegt",
  "page.update": "Seite geändert",
  "page.delete": "Seite gelöscht",
  "page.duplicate": "Seite dupliziert",
  "page.changeStatus": "Status geändert",
  "page.changeTitle": "Titel geändert",
  "page.changeSlug": "URL-Anhang geändert",
  "page.changeNum": "Reihenfolge geändert",
  "page.changeTemplate": "Vorlage geändert",
  "file.create": "Datei hochgeladen",
  "file.update": "Datei geändert",
  "file.delete": "Datei gelöscht",
  "file.replace": "Datei ersetzt",
  "file.changeName": "Dateiname geändert",
  "file.changeSort": "Reihenfolge geändert",
  "site.update": "Website geändert",
  "site.changeTitle": "Titel geändert",
  "user.create": "Account angelegt",
  "user.delete": "Account gelöscht",
  "user.changeEmail": "E-Mail geändert",
  "user.changeName": "Name geändert",
  "user.changeRole": "Rolle geändert",
  "user.changePassword": "Passwort geändert",
};

panel.plugin("sveneisenschmidt/changelog", {
  components: {
    "k-blog-changelog-view": {
      props: { entries: Array },
      methods: {
        when(time) {
          // "11.10.2026, 09:17": dates as in the Panel lists.
          return new Date(time).toLocaleString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
        },
        what(e) {
          const text = actions[e.action] || e.action;
          // Older entries have no kind and keep "Seite".
          return e.kind && e.action.startsWith("page.") ? text.replace("Seite", e.kind) : text;
        },
      },
      template: `
        <k-panel-inside class="k-blog-changelog-view">
          <k-header>Verlauf</k-header>
          <k-empty v-if="entries.length === 0" icon="clock">Noch keine Änderungen.</k-empty>
          <table v-else class="blog-log">
            <thead>
              <tr><th>Wann</th><th>Wer</th><th>Was</th><th>Wo</th><th>Änderung</th></tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in entries" :key="i">
                <td class="blog-log-time">{{ when(e.time) }}</td>
                <td>{{ e.name || "Automatisch" }}</td>
                <td>{{ what(e) }}</td>
                <td><k-link v-if="e.link" :to="e.link">{{ e.title }}</k-link><span v-else>{{ e.title }}</span></td>
                <td>
                  <div v-for="c in e.changes" :key="c.field" class="blog-log-change">
                    <strong>{{ c.label }}</strong>:
                    <del v-if="c.old">{{ c.old }}</del>
                    <span v-if="c.old && c.new"> → </span>
                    <ins v-if="c.new">{{ c.new }}</ins>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </k-panel-inside>
      `,
    },
  },
});
