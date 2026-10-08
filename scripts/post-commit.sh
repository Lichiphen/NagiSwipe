#!/bin/sh
# Git post-commit hook: keep the jsDelivr URLs in the READMEs pinned to the latest commit,
# and the documentation site (site/build.py) in step with the READMEs.
# Install once:  cp scripts/post-commit.sh .git/hooks/post-commit
#
# When a commit changes NagiSwipe-main.js / .css or NagiManga.js, the READMEs are
# rewritten to @<commit>. Then the site pages are generated again. Whatever changed is
# committed right after ("Docs: pin jsDelivr URLs ..." / "Site: rebuild ...").
# Files with uncommitted edits of your own are left alone (run the scripts by hand).
[ -n "$NAGI_CACHEBUST" ] && exit 0
cd "$(git rev-parse --show-toplevel)" || exit 0
FILES="README.md README.en.md manga/README.md manga/README.en.md"
SITE="index.html nagiswipe.html nagimanga.html nagilog.html guide.html index.en.html nagiswipe.en.html nagimanga.en.html nagilog.en.html guide.en.html demo.html sitemap.xml site/search.json site/search.en.json"
git diff --quiet HEAD -- $FILES $SITE || { echo "cachebust: README かサイトのページに未コミットの変更があるため、jsDelivr の URL とサイトは更新しませんでした"; exit 0; }
PY=""
for c in python3 python; do "$c" -c "" >/dev/null 2>&1 && { PY=$c; break; }; done
[ -n "$PY" ] || { echo "cachebust: Python が見つからないため、jsDelivr の URL とサイトは更新しませんでした"; exit 0; }
"$PY" scripts/cachebust.py $FILES >/dev/null || exit 0
PINNED=0
git diff --quiet -- $FILES || PINNED=1
"$PY" site/build.py >/dev/null || echo "cachebust: site/build.py が失敗しました（リンク切れなど）。python site/build.py で確認してください"
if ! git diff --quiet -- $FILES $SITE; then
    if [ "$PINNED" = 1 ]; then
        MSG="Docs: pin jsDelivr URLs to $(git log -1 --format=%h) and rebuild the site"
    else
        MSG="Site: rebuild from the READMEs"
    fi
    NAGI_CACHEBUST=1 git commit -q -m "$MSG" -- $FILES $SITE
    echo "cachebust: README の jsDelivr の URL とサイトのページを更新してコミットしました"
fi
exit 0
