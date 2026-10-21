# Delete all recipes

In Admin > Recipes choose **Delete all recipes**. The confirmation page counts
all recipes across every brand, including hidden records, regardless of list filters.
Type **DELETE ALL RECIPES** and press the permanent-delete button. The total must
still match when deletion starts. A backup link is available before confirmation.

The operation snapshots the current recipe IDs. It deletes ingredients and steps
with their recipes, unlinks label records, and removes pictures only when no
remaining recipe, ingredient, step or label uses them. Brands and labels are kept.
Recipes added after the snapshot are kept. Complete or cancel any running imports
before deleting recipes.

Keep the progress page open. After an interruption, open **Delete all recipes**
again in the same signed-in browser session and press **Resume deletion**. Completed
batches remain deleted. If the session expired, start again to delete the remaining
recipes after checking the new count. A failure rolls back its current batch.

The endpoint requires an admin session, POST and the CSRF token. Batch requests
also carry the operation token and current position, so repeating a request does
not delete an additional batch. Deletion is permanent without a backup.
