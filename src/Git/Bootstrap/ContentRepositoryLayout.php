<?php

declare(strict_types=1);

namespace App\Git\Bootstrap;

use App\Core\Config\PatchnotesConfig;
use App\Git\Enum\RepositoryName;

/**
 * Initial structure of the `content` repository (SPEC.md § 5.1).
 *
 * Everything language-dependent — the CODEOWNERS entries, the glossary and style guide files — is
 * derived from patchnotes.languages, so adding a language stays a configuration change (§ 24.15).
 * The JSON schemas for facts and cards are written by M5, which defines their fields.
 */
final readonly class ContentRepositoryLayout implements RepositoryLayout
{
    public function __construct(private PatchnotesConfig $config)
    {
    }

    public function repository(): RepositoryName
    {
        return RepositoryName::Content;
    }

    public function files(): array
    {
        $files = [
            'README.md' => $this->readme(),
            'LICENSE' => $this->license(),
            'CONTRIBUTING.md' => $this->contributing(),
            'CODEOWNERS' => $this->codeowners(),
            '.github/workflows/validate.yml' => $this->workflow(),
            '.gitattributes' => "* text=auto eol=lf\n*.md text eol=lf\n*.yml text eol=lf\n",
            'changes/.gitkeep' => '',
            'bills/.gitkeep' => '',
            'digests/.gitkeep' => '',
            'plenary/.gitkeep' => '',
            'schemas/.gitkeep' => '',
        ];

        foreach ($this->config->languages() as $language) {
            $files['glossary/'.$language.'.yml'] = $this->glossaryPlaceholder($language);
            $files['style/'.$language.'.md'] = $this->stylePlaceholder($language);
        }

        return $files;
    }

    private function readme(): string
    {
        $languages = implode(', ', $this->config->languages());

        return <<<MARKDOWN
            # Patchnotes — Inhalte

            Erklärungen zu Gesetzesänderungen in einfacher Sprache, übersetzt in: {$languages}
            (Mastersprache: {$this->config->masterLanguage()}).

            ## Aufbau

            ```
            changes/<jahr>/<change-id>/facts.yml   Strukturierte Fakten: Daten, Beträge, Fristen, Quellen
            changes/<jahr>/<change-id>/<lang>.md   Karte je Sprache (feste Abschnitte, Platzhalter für Zahlen)
            bills/<dip-id>/                        Gesetzentwürfe
            digests/<jahr>-W<woche>/               Wöchentliche Zusammenfassung
            plenary/<jahr>-W<woche>/               "Bundestag diese Woche"
            glossary/<lang>.yml                    Deutsche Begriffe und ihre Wiedergabe
            style/<lang>.md                        Stilrichtlinie für Menschen und für die Prompts
            taxonomy.yml                           Zielgruppen-Tags und Themen
            schemas/                               JSON-Schemas für facts.yml und Karten
            ```

            **Fakten stehen nur in `facts.yml`.** In den Texten stehen Platzhalter
            (`{{ amount:blue_card_salary_threshold.new }}`, `{{ date:effective.0 }}`), damit eine
            Übersetzung niemals eine Zahl oder ein Datum verändern kann.

            ## Qualität

            Karten werden von KI erstellt und automatisch geprüft; Menschen korrigieren sie per Pull
            Request. Nicht geprüfte Karten sind auf der Website als solche gekennzeichnet.
            Korrekturen werden öffentlich protokolliert (`corrections` in `facts.yml`).

            Dies ist Information, keine Rechtsberatung. Verbindlich ist allein der deutsche Text.
            MARKDOWN;
    }

    private function license(): string
    {
        return <<<'TEXT'
            Creative Commons Attribution 4.0 International (CC BY 4.0)

                https://creativecommons.org/licenses/by/4.0/legalcode

            Die Inhalte dieses Repositories (Karten, Übersetzungen, Glossare, Zusammenfassungen)
            dürfen unter Nennung von Patchnotes und mit Link auf die Quelle weiterverwendet werden.

            Die zitierten Gesetzestexte selbst sind amtliche Werke (§ 5 UrhG) und gemeinfrei.
            TEXT;
    }

    private function contributing(): string
    {
        $languages = implode(', ', $this->config->languages());

        return <<<MARKDOWN
            # Mitmachen: prüfen und übersetzen

            Sprachen: {$languages}. Mastersprache: {$this->config->masterLanguage()} — alle anderen
            Sprachen werden **aus dem Master** übersetzt, niemals über eine Kette.

            ## Regeln

            1. **Zahlen und Daten nie direkt schreiben.** Nur Platzhalter verwenden; die Werte stehen
               in `facts.yml` und werden beim Rendern eingesetzt.
            2. **Abschnittsschlüssel nicht ändern.** Überschriften dürfen übersetzt werden, der Anker
               (`{#summary}`, `{#what_changes}`, `{#who}`, `{#when}`, `{#what_to_do}`, `{#details}`)
               bleibt gleich.
            3. **Deutsche Begriffe stehen lassen**, wenn Menschen sie in Behördenbriefen sehen
               (Aufenthaltstitel, Bürgergeld, Elterngeld …), mit kurzer Erklärung aus `glossary/`.
            4. **Keine Rechtsberatung.** "Das Gesetz sieht vor …" statt "Sie müssen …".
            5. **Neutral bleiben.** Keine Bewertungen, keine Prognosen, keine Parteipositionen.
            6. Einfache Sprache (Niveau B1), kurze Sätze.

            ## Ablauf

            - Änderungen als Pull Request. Der Bot prüft automatisch: Platzhalter, Glossar, Sprache,
              Länge, verbotene Formulierungen — und kommentiert das Ergebnis.
            - Ein Mensch mit Schreibrechten mergt. Nach dem Merge erscheint die Änderung auf der Website.
            MARKDOWN;
    }

    /**
     * CODEOWNERS per language, so translations are reviewed by native speakers (SPEC.md § 5.1).
     */
    private function codeowners(): string
    {
        $lines = [
            '# Reviewers per language. Replace the placeholder teams with real ones.',
            '# Derived from patchnotes.languages — regenerate after adding a language.',
            '',
        ];

        foreach ($this->config->languages() as $language) {
            $lines[] = \sprintf('# /**/%s.md        @your-org/team-%s', $language, $language);
            $lines[] = \sprintf('# /glossary/%s.yml  @your-org/team-%s', $language, $language);
            $lines[] = \sprintf('# /style/%s.md      @your-org/team-%s', $language, $language);
        }

        $lines[] = '';
        $lines[] = '# /schemas/         @your-org/patchnotes-maintainers';
        $lines[] = '# /taxonomy.yml     @your-org/patchnotes-maintainers';

        return implode("\n", $lines)."\n";
    }

    private function workflow(): string
    {
        return <<<'YAML'
            ---
            name: Validate

            on:
              pull_request: ~
              push:
                branches: [main]

            permissions:
              contents: read

            jobs:
              validate:
                name: Facts and cards
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@v5
                    with:
                      persist-credentials: false

                  - name: Install validators
                    run: pipx install check-jsonschema

                  - name: Validate facts.yml against the schema
                    run: |
                      if [ -f schemas/facts.schema.json ]; then
                        find changes -name 'facts.yml' -print0 \
                          | xargs -0 --no-run-if-empty check-jsonschema --schemafile schemas/facts.schema.json
                      else
                        echo "schemas/facts.schema.json is not present yet, skipping"
                      fi

                  - name: Section anchors are intact
                    run: |
                      missing=0
                      for file in $(find changes bills -name '*.md' 2>/dev/null); do
                        if ! grep -q '{#summary}' "$file"; then
                          echo "$file: the summary section is missing"
                          missing=1
                        fi
                      done
                      exit $missing
            YAML;
    }

    private function glossaryPlaceholder(string $language): string
    {
        return <<<YAML
            # Glossary for "{$language}": German term => how to render it.
            #
            # Aufenthaltstitel:
            #   render: "Aufenthaltstitel (…)"
            #   explanation: "…"
            #   keep_german: true
            #
            # Generated with AI at bootstrap and reviewed by native speakers.
            YAML;
    }

    private function stylePlaceholder(string $language): string
    {
        return <<<MARKDOWN
            # Style guide — {$language}

            This file is read by humans **and** passed into every AI prompt for this language.

            - Simple language, level B1; short sentences.
            - Address the reader politely (formal "you").
            - Keep German terms people meet in official letters, add a short explanation.
            - Never give legal advice: "the law provides …", not "you must …".
            - Numbers, amounts and dates only through placeholders.
            - Neutral tone: no opinions, no forecasts, no party positions.
            MARKDOWN;
    }
}
