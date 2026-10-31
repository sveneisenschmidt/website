// The Panel view "Besucher": the visits of the last 30 days. A click on the
// bar of a day shows the pages, origins and countries of that day only. The page
// "Einstellungen" edits the own IP addresses (settings.yml). The file needs
// no build step: the Panel includes the Vue template compiler (option
// panel.vue.compiler, on by default) and compiles the template in the browser.
panel.plugin("website/analytics", {
  components: {
    "k-blog-analytics-view": {
      props: { summary: Object },
      data() {
        return { picked: null };
      },
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
        botReports() {
          const b = this.summary.bots;
          return [
            { label: "Bots mit Namen", value: String(b.named), info: "Abrufe, letzte 30 Tage" },
            { label: "Verdacht", value: String(b.suspect), info: "Abrufe, letzte 30 Tage" },
          ];
        },
        max() {
          return Math.max(1, ...this.summary.days.map((d) => d.visits));
        },
        ticks() {
          const days = this.summary.days;
          return [days[0], days[Math.floor(days.length / 2)], days[days.length - 1]].map((d) => this.day(d.date));
        },
        pickedDay() {
          return this.picked === null ? null : this.summary.days[this.picked];
        },
        pages() {
          return this.pickedDay ? this.pickedDay.pages : this.summary.pages;
        },
        referrers() {
          return this.pickedDay ? this.pickedDay.referrers : this.summary.referrers;
        },
        countries() {
          return this.pickedDay ? this.pickedDay.countries : this.summary.countries;
        },
        scope() {
          return this.pickedDay ? "am " + this.day(this.pickedDay.date) : "letzte 30 Tage";
        },
      },
      methods: {
        day(date) {
          // "11.10.": the date format of the Panel lists, without the year.
          // Noon local time keeps the date the same in every time zone; a
          // date without a time would be parsed as midnight UTC.
          return new Date(date + "T12:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit" });
        },
        country(code) {
          // The German name of an ISO 3166 code, from the browser.
          return code === "" ? "Unbekannt" : new Intl.DisplayNames(["de"], { type: "region" }).of(code);
        },
        height(visits) {
          return (visits / this.max) * 100 + "%";
        },
        label(d) {
          return `${this.day(d.date)}: ${d.visits} ${d.visits === 1 ? "Besuch" : "Besuche"}, ${d.views} Seitenaufrufe`;
        },
        pick(index) {
          this.picked = this.picked === index ? null : index;
        },
      },
      template: `
        <k-panel-inside class="k-blog-analytics-view">
          <k-header>
            Besucher
            <template #buttons>
              <k-button icon="cog" size="sm" variant="filled" link="besucher/einstellungen">Einstellungen</k-button>
            </template>
          </k-header>
          <k-stats :reports="reports" size="large" class="blog-stats-tiles" />

          <section class="blog-stats-section">
            <header class="blog-stats-head">
              <k-headline>Besuche pro Tag</k-headline>
              <template v-if="pickedDay">
                <span>Auswahl: <strong>{{ label(pickedDay) }}</strong></span>
                <k-button variant="filled" size="sm" @click="picked = null">Alle 30 Tage</k-button>
              </template>
            </header>
            <div class="blog-stats-chart">
              <div class="blog-stats-scale" aria-hidden="true"><span>{{ max }}</span><span>0</span></div>
              <div class="blog-stats-bars" role="group" aria-label="Besuche pro Tag. Ein Tag zeigt nur seine Seiten, Herkunft und Länder.">
                <button
                  v-for="(d, i) in summary.days"
                  :key="d.date"
                  type="button"
                  class="blog-stats-col"
                  :class="{ 'is-picked': picked === i, 'is-dimmed': picked !== null && picked !== i }"
                  :aria-label="label(d)"
                  :aria-pressed="String(picked === i)"
                  @click="pick(i)"
                >
                  <span class="blog-stats-bar" :style="{ height: height(d.visits) }"></span>
                  <span class="blog-stats-tip">{{ label(d) }}</span>
                </button>
              </div>
              <div class="blog-stats-ticks"><span v-for="t in ticks" :key="t">{{ t }}</span></div>
            </div>
          </section>

          <section class="blog-stats-section blog-stats-tables">
            <div>
              <k-headline>Seiten <span class="blog-stats-scope">{{ scope }}</span></k-headline>
              <k-empty v-if="pages.length === 0" icon="page">{{ pickedDay ? "An diesem Tag keine Besuche." : "Noch keine Besuche." }}</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Seite</th><th class="blog-stats-num">Besuche</th></tr></thead>
                <tbody>
                  <tr v-for="p in pages" :key="p.id">
                    <td><k-link v-if="p.url" :to="p.url" target="_blank">{{ p.title }}</k-link><span v-else>{{ p.title }}</span></td>
                    <td class="blog-stats-num">{{ p.visits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div>
              <k-headline>Gekommen über <span class="blog-stats-scope">{{ scope }}</span></k-headline>
              <k-empty v-if="referrers.length === 0" icon="globe">{{ pickedDay ? "An diesem Tag keine Besuche über andere Websites." : "Noch keine Besuche über andere Websites." }}</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Website</th><th class="blog-stats-num">Besuche</th></tr></thead>
                <tbody>
                  <tr v-for="r in referrers" :key="r.host">
                    <td>{{ r.host }}</td>
                    <td class="blog-stats-num">{{ r.visits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div>
              <k-headline>Länder <span class="blog-stats-scope">{{ scope }}</span></k-headline>
              <k-empty v-if="countries.length === 0" icon="globe">{{ pickedDay ? "An diesem Tag keine Besuche." : "Noch keine Besuche." }}</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Land</th><th class="blog-stats-num">Besuche</th></tr></thead>
                <tbody>
                  <tr v-for="c in countries" :key="c.code">
                    <td>{{ country(c.code) }}</td>
                    <td class="blog-stats-num">{{ c.visits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="blog-stats-section">
            <k-headline>Bots</k-headline>
            <k-stats :reports="botReports" class="blog-stats-tiles" />
            <k-empty v-if="summary.bots.names.length === 0" icon="chart">Keine Bots, die sich mit Namen melden.</k-empty>
            <table v-else class="blog-log">
              <thead><tr><th>Name</th><th class="blog-stats-num">Abrufe</th></tr></thead>
              <tbody>
                <tr v-for="b in summary.bots.names" :key="b.name">
                  <td>{{ b.name }}</td>
                  <td class="blog-stats-num">{{ b.hits }}</td>
                </tr>
              </tbody>
            </table>
          </section>

          <k-text class="blog-stats-note">
            Ein Besuch ist ein Browser an einem Tag. Wechselt ein Handy das Netz, zählt das als neuer Besuch. Die Zählung setzt keine Cookies und speichert keine IP-Adressen, nur eine verschlüsselte Kennung von Adresse und Netz. Den Schlüssel dafür gibt es nur auf dem Server. Eingeloggte Accounts und die eigenen IP-Adressen (Besucher › Einstellungen) zählen nicht mit. Das Land kommt aus der IP-Adresse, nachgeschlagen in der Länderliste von <a href="https://db-ip.com" target="_blank">DB-IP</a>.
            Als Besuch zählt nur ein Browser, der eine Seite öffnet. „Bots mit Namen“ nennen sich selbst, zum Beispiel Googlebot. „Verdacht“ gibt sich als Browser aus, schickt aber nicht die Angaben, die jeder Browser beim Öffnen einer Seite mitschickt.
          </k-text>
        </k-panel-inside>
      `,
    },
    // The page "Einstellungen". "Speichern" and Cmd+S write the fields to
    // the site through Kirby's API (PATCH site).
    "k-blog-analytics-settings-view": {
      props: { fields: Object, value: Object },
      data() {
        return { values: { ...this.value }, saving: false };
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
      },
      template: `
        <k-panel-inside class="k-blog-analytics-settings-view">
          <k-header>
            Einstellungen
            <template #buttons>
              <k-button icon="check" variant="filled" :disabled="saving" @click="save">Speichern</k-button>
            </template>
          </k-header>
          <k-fieldset :fields="fields" :value="values" @input="values = $event" />
        </k-panel-inside>
      `,
    },
  },
});
