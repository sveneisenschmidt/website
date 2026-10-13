// The Panel view "Besucher": the visits of the last 30 days. The file needs
// no build step: the Panel includes the Vue template compiler (option
// panel.vue.compiler, on by default) and compiles the template in the browser.
panel.plugin("sveneisenschmidt/analytics", {
  components: {
    "k-blog-analytics-view": {
      props: { summary: Object },
      computed: {
        reports() {
          const t = this.summary.totals;
          return [
            { label: "Heute", value: String(t.today), info: "Besuche" },
            { label: "Letzte 7 Tage", value: String(t.week), info: "Besuche" },
            { label: "Letzte 30 Tage", value: String(t.month), info: "Besuche" },
            { label: "Seitenaufrufe", value: String(t.views), info: "Letzte 30 Tage" },
          ];
        },
        max() {
          return Math.max(1, ...this.summary.days.map((d) => d.visits));
        },
        ticks() {
          const days = this.summary.days;
          return [days[0], days[Math.floor(days.length / 2)], days[days.length - 1]].map((d) => this.day(d.date));
        },
      },
      methods: {
        day(date) {
          // "11.10.": the date format of the Panel lists, without the year.
          // Noon local time keeps the date the same in every time zone; a
          // date without a time would be parsed as midnight UTC.
          return new Date(date + "T12:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit" });
        },
        height(visits) {
          return (visits / this.max) * 100 + "%";
        },
        label(d) {
          return `${this.day(d.date)}: ${d.visits} ${d.visits === 1 ? "Besuch" : "Besuche"}, ${d.views} Seitenaufrufe`;
        },
      },
      template: `
        <k-panel-inside class="k-blog-analytics-view">
          <k-header>Besucher</k-header>
          <k-stats :reports="reports" size="large" class="blog-stats-tiles" />

          <section class="blog-stats-section">
            <k-headline>Besuche pro Tag</k-headline>
            <div class="blog-stats-chart" role="img" :aria-label="'Besuche pro Tag, höchstens ' + max">
              <div class="blog-stats-scale"><span>{{ max }}</span><span>0</span></div>
              <div class="blog-stats-bars">
                <div v-for="d in summary.days" :key="d.date" class="blog-stats-col" :aria-label="label(d)">
                  <div class="blog-stats-bar" :style="{ height: height(d.visits) }"></div>
                  <span class="blog-stats-tip">{{ label(d) }}</span>
                </div>
              </div>
              <div class="blog-stats-ticks"><span v-for="t in ticks" :key="t">{{ t }}</span></div>
            </div>
          </section>

          <section class="blog-stats-section blog-stats-tables">
            <div>
              <k-headline>Seiten</k-headline>
              <k-empty v-if="summary.pages.length === 0" icon="page">Noch keine Besuche.</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Seite</th><th class="blog-stats-num">Besuche</th></tr></thead>
                <tbody>
                  <tr v-for="p in summary.pages" :key="p.id">
                    <td><k-link v-if="p.url" :to="p.url" target="_blank">{{ p.title }}</k-link><span v-else>{{ p.title }}</span></td>
                    <td class="blog-stats-num">{{ p.visits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div>
              <k-headline>Gekommen über</k-headline>
              <k-empty v-if="summary.referrers.length === 0" icon="globe">Noch keine Besuche über andere Websites.</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Website</th><th class="blog-stats-num">Besuche</th></tr></thead>
                <tbody>
                  <tr v-for="r in summary.referrers" :key="r.host">
                    <td>{{ r.host }}</td>
                    <td class="blog-stats-num">{{ r.visits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <k-text class="blog-stats-note">
            Ein Besuch ist ein Browser an einem Tag. Wechselt ein Handy das Netz, zählt das als neuer Besuch. Die Zählung setzt keine Cookies und speichert keine IP-Adressen.
            Suchmaschinen, andere Programme und eingeloggte Accounts zählen nicht mit.
          </k-text>
        </k-panel-inside>
      `,
    },
  },
});
