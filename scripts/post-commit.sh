#!/bin/sh
# Git post-commit hook: keep the jsDelivr URLs in the READMEs pinned to the latest commit.
# Install once:  cp scripts/post-commit.sh .git/hooks/post-commit
#
# When a commit changes NagiSwipe-main.js / .css or NagiManga.js, the READMEs are
# rewritten to @<commit> and committed right after ("Docs: pin jsDelivr URLs").
# READMEs with uncommitted edits of your own are left alone (run the script by hand).
[ -n "$NAGI_CACHEBUST" ] && exit 0
cd "$(git rev-parse --show-toplevel)" || exit 0
FILES="README.md manga/README.md"
git diff --quiet HEAD -- $FILES || { echo "cachebust: README に未コミットの変更があるため、jsDelivr の URL は更新しませんでした"; exit 0; }
PY=""
for c in python3 python; do "$c" -c "" >/dev/null 2>&1 && { PY=$c; break; }; done
[ -n "$PY" ] || { echo "cachebust: Python が見つからないため、jsDelivr の URL は更新しませんでした"; exit 0; }
"$PY" scripts/cachebust.py $FILES >/dev/null || exit 0
if ! git diff --quiet -- $FILES; then
    NAGI_CACHEBUST=1 git commit -q -m "Docs: pin jsDelivr URLs to $(git log -1 --format=%h)" -- $FILES
    echo "cachebust: README の jsDelivr の URL を最新のコミットに合わせてコミットしました"
fi
exit 0
