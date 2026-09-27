#!/bin/sh
# Git post-commit hook: keep the ?<commit> cache busters in the READMEs in step.
# Install once:  cp scripts/post-commit.sh .git/hooks/post-commit
#
# When a commit changes NagiSwipe-main.js / .css or NagiManga.js, the READMEs are
# rewritten with the new hashes and committed right after ("Docs: cache busters").
# READMEs with uncommitted edits of your own are left alone (run the script by hand).
[ -n "$NAGI_CACHEBUST" ] && exit 0
cd "$(git rev-parse --show-toplevel)" || exit 0
FILES="README.md manga/README.md"
git diff --quiet HEAD -- $FILES || { echo "cachebust: README に未コミットの変更があるため、キャッシュバスターは更新しませんでした"; exit 0; }
PY=""
for c in python3 python; do "$c" -c "" >/dev/null 2>&1 && { PY=$c; break; }; done
[ -n "$PY" ] || { echo "cachebust: Python が見つからないため、キャッシュバスターは更新しませんでした"; exit 0; }
"$PY" scripts/cachebust.py $FILES >/dev/null || exit 0
if ! git diff --quiet -- $FILES; then
    NAGI_CACHEBUST=1 git commit -q -m "Docs: cache busters for $(git log -1 --format=%h)" -- $FILES
    echo "cachebust: README のキャッシュバスターを更新してコミットしました"
fi
exit 0
