# Recipe pack format

A recipe pack moves recipes in and out of MustrHQ Recipes: **Admin → Import and export**.
It is a zip holding `recipes.json` and the photos it names. A bare `recipes.json` with no
photos also imports.

```
recipes-pack.zip
├── recipes.json
└── images/
    ├── k01-dish.jpg
    ├── k01-ing-1.jpg
    └── k01-step-1.jpg
```

Photos can sit anywhere in the zip; they are matched by file name, ignoring upper and lower
case. JPG, PNG, WebP and GIF are accepted, up to 10 MB each. Photos larger than 1800 px are
shrunk when the server has the GD extension.

## recipes.json

```json
{
  "format": "mustrhq-recipes",
  "version": 1,
  "recipes": [
    {
      "brand": "Katsu & Co",
      "brand_colour": "#B3261E",
      "code": "K01",
      "name": "Chicken katsu rice bowl",
      "description": "Crisp panko chicken on sticky rice with katsu sauce.",
      "makes": "1 bowl",
      "prep_minutes": 6,
      "cook_minutes": 8,
      "allergens": ["gluten", "eggs", "soya", "celery"],
      "diets": ["halal"],
      "safety_note": "Chicken must reach 75 °C core.",
      "live": true,
      "photo": "k01-dish.jpg",
      "ingredients": [
        { "name": "Breaded chicken thigh", "amount": "150", "unit": "g", "note": "prep fridge, shelf 2", "photo": "k01-ing-1.jpg" }
      ],
      "steps": [
        { "title": "Fry the chicken", "text": "Fry at 175 °C until deep golden.", "tip": "Lay it away from you.", "timer": "6:00", "photo": "k01-step-1.jpg" }
      ]
    }
  ]
}
```

| Field | Required | Notes |
|---|---|---|
| `format`, `version` | No | Must be `mustrhq-recipes` and `1` when present. |
| `brand` | No | Created if it does not exist yet. `brand_colour` (`#RRGGBB`) is used only for a new brand. |
| `code` | No, but recommended | The menu or ticket number. Used to match existing recipes. |
| `name` | **Yes** | Up to 140 characters. |
| `makes` | No | Free text: "1 bowl", "4 portions", "2 kg". |
| `prep_minutes`, `cook_minutes` | No | Whole minutes. |
| `allergens` | No | Keys: `celery`, `gluten`, `crustaceans`, `eggs`, `fish`, `lupin`, `milk`, `molluscs`, `mustard`, `nuts`, `peanuts`, `sesame`, `soya`, `sulphites`. Common names such as "Cereals containing gluten", "Tree nuts" or "Dairy" are recognised. Anything else is left out and flagged in the preview. |
| `diets` | No | `vegan`, `vegetarian`, `halal`, `gluten_free`. |
| `safety_note` | No | Shown in amber on the recipe page. |
| `live` | No | `false` imports the recipe hidden from the kitchen. Default `true`. |
| `photo` | No | File name of the finished-dish photo. |
| `ingredients[]` | No | `name` (required), `amount`, `unit`, `note`, `photo`. A plain string is read as a name. Keep `amount` a plain number so the kitchen can scale batches. |
| `steps[]` | No | `text`, `title`, `tip`, `timer`, `photo`. A step needs a title or text. A plain string is read as the text. `timer` is `"m:ss"` or a number of seconds. |

The file may also be a bare list of recipes (`[ {...}, {...} ]`). A ```` ```json ```` fence
around the file, as ChatGPT sometimes adds, is ignored.

## How importing matches recipes

A recipe in the pack **replaces** an existing one with the same brand and code, or the same
brand and name when there is no code. Its details, ingredients and steps are replaced by the
pack's. For each photo slot:

- a photo in the pack replaces the current one,
- a photo named but missing from the zip keeps the current one (and is flagged),
- an empty `photo` keeps the current one.

Everything else is added as a new recipe. The preview shows what will happen to each recipe,
and you choose which ones to import. Nothing changes until you confirm, and a failed import
changes nothing.

## Exporting

Exports use the same format, with photo files named
`<brand>__<code>__dish.jpg`, `__ing-1`, `__step-1` and so on. An export imports back unchanged,
so it doubles as a portable backup and a way to copy recipes to another kitchen.

## MustrHQ Station packs

Packs from MustrHQ Station (`"format": "mustrhq-recipe-pack"`) import directly. The importer reads:

| Station field | Becomes |
|---|---|
| `brands[].colour` | the brand colour, for new brands |
| `recipes[].preview_image`, or `<brand>__<code>__preview.png` | the finished-dish photo |
| `steps[].image`, or `<brand>__<code>__step<N>.png` | the photo for step N |
| `ingredients[].component` + `components[].image`, or `component__<code>.png` | the ingredient photo |
| `ingredients[].qty` such as `"1 cutlet (180 g raw)"` | amount `1`, unit `cutlet`, note `(180 g raw)` |
| `halal`, `vegetarian`, `vegan` | diet tags |
| `minutes_per_portion` | cook time, and makes "1 portion" |
| `qc_note`, `pack_notes`, `chef_note`, `utensils` | lines in the recipe note |
| `name_local`, `station_hint` | the description |

A step that names a single cooking time ("Simmer for 8 min") gets an 8-minute timer. Several
`recipes*.json` files in one zip are merged in name order. Brand slugs follow Station's rule: lower
case, `&` becomes `and`, anything else that is not a letter or number becomes `-`.


## Kitchen image-only ZIPs (1.4.2)

The existing Kitchen_All_Images_Small_Part_01.zip through Part_37.zip can now be
uploaded directly without recipes.json. Existing recipes are required. A bundled
kitchen reference map matches brand and code for dish pictures, exact instruction
text for method pictures, and ingredient names for ready-item references.
Only image columns are updated. Quantities, instructions, allergens, publication
and all other recipe fields stay unchanged. Unmatched pictures are listed in the
preview and skipped. Utensil pictures have no database slot and remain reference
files in the original ZIP. This is specific to the supplied kitchen image set;
arbitrary unrelated image filenames are not matched.

Use the preview before importing. Recipe JSON imports still replace recipe fields;
image-only mode applies only to a ZIP containing pictures and no JSON files.
All supplied kitchen images are AI-generated references requiring chef review.


## Separate image import (1.4.3)

Choose **Import images only**, then upload a ZIP. This form ignores JSON entries,
so it cannot replace recipe text even if recipes.json was accidentally included.
Recipes must already exist. The preview expands to show each picture's destination
slot and lists all unmatched filenames.

Native export names also work without metadata: `brand__code__dish.png`,
`brand__code__step-1.png`, `brand__code__ing-1.png`. JPEG, WebP and GIF extensions
are supported too. Brand and code must match an existing recipe identity. Use the
slugs from an export. Indexes are 1-based current step/ingredient positions; review
these after reordering a recipe. Known kitchen reference step filenames use their
reference instruction instead of assuming a numeric position.

Duplicate basenames in different ZIP folders cause an error. Multiple pictures
competing for one slot and duplicate recipe identities are skipped. Generic image
imports never guess from visual content or a vague dish-name resemblance. If a
recipe changes after preview, reload and review the placements again.
