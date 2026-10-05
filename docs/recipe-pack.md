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
