# doctrine-anonymization

Marks personal data on Doctrine entities and generates **anonymized read-only database
views** from them. Useful whenever something needs to read your data but must not see
personal details - reporting, analytics, a demo environment, an external contractor,
or an LLM.

```bash
composer require adt/doctrine-anonymization
```

## How it works

Anonymization happens **in the database**, in the definition of the generated views -
loaded entities are never rewritten at runtime:

1. You mark personal columns with `#[Anonymize(...)]`.
2. `anonymization:generate-views` reads the entity metadata and creates
   `<dbname>_anon.<table>` views where marked columns are replaced by an SQL
   expression.
3. Consumers connect with a read-only account that has `GRANT SELECT` **only** on the
   anon schema, so unmasked data never reaches them.

Views are created with `SQL SECURITY DEFINER`, so the read-only account needs no
privileges on the source tables.

## Marking the data

```php
use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use ADT\DoctrineAnonymization\Attributes\Description;

class Client
{
    #[ORM\Column]
    #[Anonymize(AnonymizationType::EMAIL)]
    public ?string $email = null;

    #[ORM\Column]
    #[Anonymize(AnonymizationType::NOTE)]
    #[Description('Free-text note about the client written by staff.')]
    public ?string $note = null;

    #[ORM\Column]
    public ?float $weight = null;      // not personal data, passes through 1:1
}
```

### Reporting dimensions

Not every entity is a data subject. Staff, branches and companies are things you
report *by*, so their identity has to stay readable:

```php
use ADT\DoctrineAnonymization\Attributes\AnonymizationExempt;

#[AnonymizationExempt]                                   // everything stays readable
class Branch { ... }

#[AnonymizationExempt(AnonymizationType::FULL_NAME)]     // only the name stays readable
class User { ... }                                       // contact details still masked
```

`SECRET` and `BANK_ACCOUNT` are masked even on exempt entities - they are never a
reporting dimension and leaking them is a security problem on its own. **Any
credential (login name, password, salt, token, session id) should therefore use
`SECRET`**, otherwise it stays readable on an exempt entity.

### Your own types

The built-in `AnonymizationType` covers the domain-neutral cases. For domain types
implement `AnonymizationTypeInterface` with your own enum - both can be mixed, since
`#[Anonymize]` accepts any implementation:

```php
enum MyType: string implements AnonymizationTypeInterface
{
    case GLYCAEMIA = 'glycaemia';
    case MEDICAL_NOTE = 'medical_note';

    public function key(): string { return $this->value; }

    public function isAnonymized(): bool
    {
        // Keep measurements real so reports over them make sense,
        // but never expose free-text notes - they contain names and diagnoses.
        return $this === self::MEDICAL_NOTE;
    }

    public function alwaysAnonymize(): bool { return false; }
    public function isDirectIdentifier(): bool { return false; }
}
```

If a type needs a specific shape of masking (e.g. keep only the year of a date),
implement `MaskingStrategy` - see `DefaultMaskingStrategy`, which does exactly that
for `DATE_OF_BIRTH`.

## Generating the views

```neon
services:
	- ADT\DoctrineAnonymization\AnonymizationPolicy
	- ADT\DoctrineAnonymization\AnonymizedViewsGenerator
	- ADT\DoctrineAnonymization\GenerateAnonymizedViewsCommand
```

```bash
# fixed mask, plus a read-only account with SELECT on the anon schema only
php bin/console anonymization:generate-views --mask \
    --schema=myapp_anon --grant-user=myapp_ro --grant-password=secret

# realistic (deterministic) values instead of a fixed mask - JOIN/GROUP BY keep working
php bin/console anonymization:generate-views --salt=... --schema=myapp_anon

# print the SQL without touching the database
php bin/console anonymization:generate-views --mask --dry-run
```

**Run it on every deploy, right after migrations.** The column list of each view is
fixed at generation time, so a newly added source column is simply absent from the
view until you regenerate - fail-closed, but also means annotating alone changes
nothing on live views.

### Masking modes

| Mode | Value becomes | JOIN / GROUP BY | Use for |
|---|---|---|---|
| `--mask` | `*****` | no | just hide the data |
| default | deterministic hash (`SHA2` + salt), shape preserved per type | yes | analytics that group by a person |

`NULL` always stays `NULL`. Keep the salt secret and stable - changing it changes all
pseudonyms.

### Views on a different server

With `--federated` the source database is mirrored on `--host` through FEDERATED
tables and the views are built on top of that mirror, so the views can live on a
different server than the data (requires the FEDERATED engine). Note the mirror
schema contains **readable** data - never grant anything on it.

## Reading the schema

Generated views do not carry over MySQL column comments, so anything reading the anon
schema sees bare column names. `SchemaDescriber` turns the `#[Description]`
attributes into `[table => [column => text]]` and always appends the target table for
foreign keys:

```php
$describer->getColumnDescriptions()['users']['DISABLED'];
// 'Account status: 1 = active, 2 = suspended, 3 = deleted. Not a boolean despite the
//  column name. Foreign key referencing table "cl_states".'
```

Without it consumers guess, and legacy names make them guess wrong - a `DISABLED`
column holding a status id looks like a boolean, so `WHERE DISABLED = 0` silently
returns nothing.

`PersonalDataColumns::getDirectIdentifierColumns()` lists column names that always
carry a masked direct identifier, for consumers that need to redact identifiers from
free-form output. Column names readable anywhere else are excluded, so redaction
cannot strip legitimate values (e.g. a masked client `NAME` vs. a readable branch
`NAME`).
