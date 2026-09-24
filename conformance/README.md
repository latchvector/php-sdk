# Conformance corpus (vendored)

**Do not edit these files here.** They are a copy. The master lives in the
Latch Vector service repository at `sdk/conformance/`, and every SDK carries its own
copy so that this repository clones and tests on its own, with no dependency on the
others.

To change a vector: edit the master, run `./sync.sh` there, and commit the result into
each SDK. A vector added to the master and not synced simply is not enforced here yet —
which is visible, unlike an edit made here that silently disagrees with the other four.

The runner for this SDK lives in its own test suite, written in this language. It reads
these files and asserts the outcome each vector states.
