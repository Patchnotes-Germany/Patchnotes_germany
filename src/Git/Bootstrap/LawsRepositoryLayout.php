<?php

declare(strict_types=1);

namespace App\Git\Bootstrap;

use App\Git\Enum\RepositoryName;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Initial structure of the `laws` repository (SPEC.md § 4.1).
 *
 * The texts themselves are amtliche Werke (§ 5 UrhG) and not protected by copyright; CC0 covers
 * only the formatting the bot adds.
 */
final readonly class LawsRepositoryLayout implements RepositoryLayout
{
    private const array JURISDICTIONS = [
        'bund', 'bw', 'by', 'be', 'bb', 'hb', 'hh', 'he', 'mv', 'ni', 'nw', 'rp', 'sl', 'sn', 'st', 'sh', 'th',
    ];

    public function __construct(
        #[Autowire('%env(SERVER_NAME)%')]
        private string $serverName,
    ) {
    }

    public function repository(): RepositoryName
    {
        return RepositoryName::Laws;
    }

    public function files(): array
    {
        return [
            'README.md' => $this->readme(),
            'LICENSE' => $this->license(),
            'CONTRIBUTING.md' => $this->contributing(),
            'schemas/law.schema.json' => $this->lawSchema(),
            'schemas/norm.frontmatter.schema.json' => $this->normSchema(),
            '.github/workflows/validate.yml' => $this->workflow(),
            '.github/CODEOWNERS' => $this->codeowners(),
            '.gitattributes' => "* text=auto eol=lf\n*.md text eol=lf\n*.yml text eol=lf\n",
        ];
    }

    private function readme(): string
    {
        return <<<MARKDOWN
            # Patchnotes — Gesetzestexte

            Dieses Repository enthält die geltenden Fassungen deutscher Gesetze und Verordnungen als
            Markdown. Es wird automatisch von [Patchnotes](https://{$this->serverName}) gepflegt:
            **jede Änderung eines Gesetzes ist ein Pull Request.**

            ## Aufbau

            ```
            bund/<slug>/_law.yml      Metadaten des Gesetzes (Titel, Abkürzung, Stand, Quelle)
            bund/<slug>/<norm>.md     Eine Datei je Norm (§, Artikel, Anlage, Eingangsformel)
            laender/<code>/<slug>/    Dasselbe für die 16 Länder (bw, by, be, …)
            <jurisdiktion>/_repealed/ Aufgehobene Gesetze bleiben mit ihrer Geschichte erhalten
            schemas/                  JSON-Schemas für _law.yml und das Front Matter der Normen
            ```

            Jede Norm steht in einer eigenen Datei, und **jeder Satz steht in einer eigenen Zeile**.
            Dadurch zeigen `git diff` und `git blame` genau, welcher Satz sich durch welches
            Änderungsgesetz geändert hat.

            ## Herkunft

            Die Texte stammen aus den amtlichen Quellen (u. a. gesetze-im-internet.de) und werden
            **deterministisch, ohne KI** nach Markdown konvertiert. Jeder Bot-Commit trägt Trailer mit
            Quelle, Quell-URL und Änderungsgesetz:

            ```
            Source: gesetze-im-internet
            Source-Url: https://www.gesetze-im-internet.de/aufenthg_2004/
            Amending-Act: BGBl. 2026 I Nr. 123
            Change-Id: 2026-bund-bgbl-i-123
            ```

            ## Keine Rechtsberatung

            Verbindlich ist ausschließlich die amtliche Veröffentlichung. Dieses Repository ist eine
            maschinenlesbare Aufbereitung und kann Fehler enthalten — Hinweise sind willkommen
            (siehe `CONTRIBUTING.md`).
            MARKDOWN;
    }

    private function license(): string
    {
        return <<<TEXT
            Gesetzestexte sind amtliche Werke im Sinne von § 5 UrhG und genießen keinen
            urheberrechtlichen Schutz. Sie können frei verwendet werden.

            Die von Patchnotes hinzugefügte Aufbereitung — Dateistruktur, Metadaten in _law.yml,
            Markdown-Formatierung, Satztrennung und Commit-Historie — wird unter CC0 1.0 Universal
            (Public Domain Dedication) zur Verfügung gestellt:

                https://creativecommons.org/publicdomain/zero/1.0/legalcode.de

            Die jeweilige Quelle jedes Textes ist in _law.yml und in den Commit-Trailern angegeben.
            TEXT;
    }

    private function contributing(): string
    {
        return <<<MARKDOWN
            # Mitmachen

            Dieses Repository wird von einem Bot gepflegt. Trotzdem sind Beiträge willkommen —
            besonders Hinweise auf Konvertierungsfehler.

            ## Was der Bot macht

            - Er synchronisiert die amtlichen konsolidierten Fassungen (täglich).
            - Änderungen eines Änderungsgesetzes landen in **einem** Pull Request.
            - Automatische Prüfungen (Schemas, Kodierung, Determinismus, Umfang der Änderung)
              entscheiden, ob der Pull Request automatisch gemergt wird.

            ## Pull Requests von Menschen

            - Werden **nie** automatisch gemergt. Der Bot kommentiert nur das Prüfergebnis.
            - Bitte nur die Aufbereitung korrigieren, nicht den Gesetzestext selbst ändern:
              maßgeblich ist die amtliche Fassung.
            - Ein Satz pro Zeile beibehalten, keine Zeilenumbrüche innerhalb eines Satzes.
            - Keine Zeitstempel, keine generierten Inhaltsverzeichnisse von Hand ändern.

            ## Fehler melden

            Bitte ein Issue mit Link auf die Norm und auf die amtliche Quelle eröffnen.
            MARKDOWN;
    }

    private function lawSchema(): string
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://'.$this->serverName.'/schemas/law.schema.json',
            'title' => '_law.yml',
            'type' => 'object',
            'required' => ['slug', 'jurisdiction', 'type', 'status', 'title', 'source', 'norms'],
            'additionalProperties' => false,
            'properties' => [
                'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+$'],
                'jurisdiction' => ['type' => 'string', 'enum' => self::JURISDICTIONS],
                'type' => ['type' => 'string', 'enum' => ['gesetz', 'verordnung', 'bekanntmachung', 'satzung', 'sonstige']],
                'status' => ['type' => 'string', 'enum' => ['in_force', 'repealed']],
                'abbreviation' => ['type' => ['string', 'null']],
                'official_abbreviation' => ['type' => ['string', 'null']],
                'title' => ['type' => 'string', 'minLength' => 1],
                'short_title' => ['type' => ['string', 'null']],
                'date_of_issue' => ['type' => ['string', 'null'], 'format' => 'date'],
                'promulgation' => ['type' => ['string', 'null']],
                'status_note' => ['type' => ['string', 'null']],
                'last_amending_act' => ['type' => ['string', 'null']],
                'source' => [
                    'type' => 'object',
                    'required' => ['name', 'url'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'url' => ['type' => 'string', 'format' => 'uri'],
                        'document_id' => ['type' => ['string', 'null']],
                    ],
                ],
                'norms' => ['type' => 'array', 'items' => ['type' => 'string']],
                'structure' => ['type' => 'array'],
                'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        return json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    private function normSchema(): string
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://'.$this->serverName.'/schemas/norm.frontmatter.schema.json',
            'title' => 'Front matter of a norm file',
            'type' => 'object',
            'required' => ['law', 'jurisdiction', 'status'],
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => ['string', 'null']],
                'law' => ['type' => 'string'],
                'jurisdiction' => ['type' => 'string', 'enum' => self::JURISDICTIONS],
                'designation' => ['type' => ['string', 'null']],
                'title' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string', 'enum' => ['in_force', 'repealed']],
            ],
        ];

        return json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
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
                name: Schemas and encoding
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@v5
                    with:
                      persist-credentials: false

                  - name: Install validators
                    run: pipx install check-jsonschema

                  - name: Validate _law.yml against the schema
                    run: |
                      find . -name '_law.yml' -print0 \
                        | xargs -0 --no-run-if-empty check-jsonschema --schemafile schemas/law.schema.json

                  - name: Reject control characters and BOM
                    run: |
                      if grep -rlIP '[\x00-\x08\x0B\x0C\x0E-\x1F]|\xEF\xBB\xBF' --include='*.md' --include='*.yml' .; then
                        echo "Control characters or BOM found in the files above"
                        exit 1
                      fi

                  - name: Reject trailing whitespace
                    run: |
                      if grep -rn --include='*.md' ' $' .; then
                        echo "Trailing whitespace found"
                        exit 1
                      fi
            YAML;
    }

    private function codeowners(): string
    {
        return <<<'TEXT'
            # Every change to the law texts is reviewed by the maintainers of the conversion pipeline.
            # Replace the placeholder with your team once the repository has one.
            #
            # *            @your-org/patchnotes-maintainers
            # /schemas/    @your-org/patchnotes-maintainers
            TEXT;
    }
}
