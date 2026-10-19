---
description: Kør hele flowet for et nyt ønske – design, udvikling, test og review
argument-hint: <beskriv dit ønske>
---

Ønske fra brugeren: $ARGUMENTS

Kør dette flow. Du er projektleder: du uddelegerer til agenterne og holder styr på status.
Stop og spørg brugeren ved de to godkendelsespunkter – fortsæt aldrig forbi dem uden svar.

1. **Design** – brug `designer`-agenten til at lave spec'en i `docs/specs/`.
   Vis brugeren en kort opsummering + åbne spørgsmål.
   ⏸ **GODKENDELSE 1:** Spørg om spec'en er godkendt eller skal rettes. Ved rettelser: send dem til designeren og spørg igen.

2. **Udvikling** – brug `udvikler`-agenten med stien til den godkendte spec.

3. **Test** – brug `tester`-agenten med spec'en og branch-navnet.
   Er resultatet IKKE KLAR: send testrapporten til `udvikler`, og test igen. Maks. 3 runder – derefter stop og vis brugeren problemet.

4. **Review** – brug `reviewer`-agenten.
   Er resultatet SKAL RETTES: send de blokerende fund til `udvikler`, derefter `tester` igen, derefter `reviewer` igen. Maks. 2 runder.

5. ⏸ **GODKENDELSE 2:** Vis brugeren: hvad er lavet, testresultat, reviewresultat, branch-navn.
   Spørg om der skal merges til `main` / laves pull request. Gør intet før brugeren siger ja.
