# Transforms and expressions

Every mapped field can run a list of transforms, in order, on the value taken from the source column (or the static
value). Each step gets the previous step's result.

Empty values stay empty in number and date transforms, so an empty price never turns into 0. A value a transform
cannot handle (text in a number transform, an impossible date) becomes a row error naming the field, the step and the
value; the rest of the file still imports.

## Text

| Transform | Arguments | Example |
|---|---|---|
| `trim` | – | `"  Shirt "` → `Shirt` |
| `lower`, `upper`, `title` | – | `red shirt` → `Red Shirt` (title) |
| `replace` | find, replace (default: nothing) | `replace("cm", " cm")`: `10cm` → `10 cm` |
| `regex` | pattern, replace (default: nothing) | `regex("^ART-(\d+)$", "$1")`: `ART-0042` → `0042` |
| `strip_html` | – | `<p>Soft &amp; warm</p>` → `Soft & warm` |
| `prefix`, `suffix` | text | `prefix("DE-")`: `42` → `DE-42` (empty values stay empty) |
| `default` | value | used when the value is empty |
| `split` | separator, position (1 = first, -1 = last) | `split(";", 2)`: `a;b;c` → `b` |
| `join` | separator, column, … | `join(" ", "Brand")`: `Shirt` → `Shirt ACME`; empty parts are left out |

Regex patterns are written without delimiters and are Unicode-aware; `(?i)` at the start ignores case. A pattern
that backtracks too much fails the row instead of hanging the run.

## Numbers

Numbers are read the way files write them: `1234.5`, `1,5`, `1.234,56`, `1,234.56`, `1 234,5`, `1'234.50`.

| Transform | Arguments | Example |
|---|---|---|
| `decimal_comma` | – | for files with decimal commas: `1.234,50` → `1234.5`, `1.234` → `1234` |
| `number` | decimals (optional), decimal separator `.` or `,` (optional) | `number(2)`: `5` → `5.00`; `number(2, ",")`: `12.5` → `12,50` (exports) |
| `math` | operator (`+ - * /`, `× ÷`, or `add`, `subtract`, `multiply`, `divide`), operand | `math("*", 1.19)` |
| `round` | decimals (default 2), mode `half_up` / `up` / `down` / `ending`, ending | `round(2, "ending", .99)`: `12.30` → `12.99` |
| `price_adjust` | percent, decimals (default 2) | `price_adjust(10)`: `19.99` → `21.99` |

`round` with mode `ending` moves to the next price with the given cents: `12.30` → `12.99`, `12.99` stays, and with
`.49`: `12.60` → `13.49`.

## Dates

`date(from_format, to_format)` with PHP date letters (`d.m.Y`, `m/d/Y`, `Y-m-d H:i:s`). `from_format` `auto` (the
default) accepts ISO dates (`2024-01-31`, with or without time) and German dates (`31.01.2024`, with or without time).
`to_format` defaults to `Y-m-d`. Impossible dates such as `31.02.2024` are row errors. No time-zone conversion.

## Value maps

`map` replaces whole values using the field's value map (`Ja` → `1`, `rot` → `Red`). Case and surrounding spaces are
ignored by default; values not in the map pass through unchanged, or are row errors when the map is strict.

## Expressions

`expr(expression)` computes a value from the whole source row. Expressions are parsed once per run; there is no
`eval` and no access to PHP functions.

| Element | Syntax |
|---|---|
| Column | `{Column Name}` (a `}` inside a name is written `}}`) |
| Current value | `value` |
| Text | `'text'` or `"text"` (a doubled quote inside is one quote) |
| Number | `42`, `1.5`, `1e3` (start with a digit: `0.5`, not `.5`) |
| Arithmetic | `+ - * / %` |
| Join text | `.` (`{Brand} . ' ' . {Name}`) |
| Compare | `= != <> < <= > >=` (numbers as numbers, text ignoring case) |
| Logic | `and`, `or`, `not`, `true`, `false` |

Functions: `IF(condition, then, else)`, `ROUND(number, decimals)`, `CONCAT(…)`, `LOWER(text)`, `UPPER(text)`,
`TRIM(text)`, `COALESCE(…)` (first non-empty), `LEN(text)`, `NUMBER(text)`, `REPLACE(text, find, replace)`,
`CONTAINS(text, part)`, `MIN(…)`, `MAX(…)`, `ABS(number)`. Function names and keywords ignore case.

Rules:

- Arithmetic with an empty value gives an empty value: `{Special price} * 1.19` stays empty when there is no special
  price.
- Text that is not a number in arithmetic, and division by zero, are row errors.
- Empty text, `0` and `false` are false in conditions; everything else is true.
- Whole numbers keep every digit; decimals have 15 significant digits.
- Expressions are limited to 2,000 characters and 40 levels of nesting.

Examples:

```text
IF({Stock} > 0, 1, 0)
ROUND(NUMBER({Preis}) * 1.19, 2)
CONCAT({Brand}, ' ', {Model}, ' ', {Color})
IF(CONTAINS({Category}, 'sale'), {Price} * 0.9, {Price})
COALESCE({EAN}, {GTIN}, {Supplier SKU})
```

## Your own transforms

Implement `Iranimij\OpenImport\Model\Transform\TransformProviderInterface` and add the class to the `providers`
argument of `Iranimij\OpenImport\Model\Transform\TransformPool` in your module's `di.xml`. A provider may also
replace a built-in transform by using its name.

## Only when

Since 1.3. Every transform can have a condition: type it into **Only when** before you add the transform. The
transform then runs only for rows where the condition is true; other rows keep the value as it is. Conditions are
expressions (see above) and can use the row's columns and `value`, the value at that step.

| Transform | Only when | Effect |
|---|---|---|
| Change price by % (15) | `{Brand} = 'Acme'` | Acme prices +15 %, other brands unchanged |
| Change price by % (-10) | `CONTAINS({Category}, 'sale')` | 10 % off in the sale category |
| Round (0, ending, .99) | `value > 20` | only prices above 20 end in .99 |

In the profile JSON a conditional transform is written as
`{"name": "price_adjust", "args": ["15"], "when": "{Brand} = 'Acme'"}`.

