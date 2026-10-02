-- Proofs: opens the pipeline's proof store index (~/GitProjects/_proofs/index.html) in Chrome.
-- hooks/git-freshness.sh compiles this into ~/Applications/Proofs.app when no app of that name is there.

set storeIndex to POSIX path of (path to home folder) & "GitProjects/_proofs/index.html"

try
	do shell script "test -f " & quoted form of storeIndex
on error
	display alert "No proof store index yet" message storeIndex & " does not exist: the first proof a /pipeline run files creates it."
	return
end try

try
	do shell script "open -b com.google.Chrome " & quoted form of storeIndex
on error errorMessage
	display alert "Could not open the proof store index" message errorMessage
end try
