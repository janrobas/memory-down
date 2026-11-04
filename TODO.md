# TODO

## Pregled MemoryDown korpusa (2026-11-04)

Pregledano prek povezanega MCP-ja: 82 aktivnih in 5 arhiviranih zapisov. Med aktivnimi je 21 `notes`, 17 `facts`, 14 `projects`, 8 `decisions`, 6 `preferences`, 5 `references`, po 3 `workflows` in `context`, po 2 `ideas` in `writings` ter 1 zapis v dodatni kategoriji `skills`. `goals` in `people` sta trenutno prazni.

- `notes` je najpogosteje uporabljena in precej mešana kategorija (opravila, nadaljnje obravnave, začasni/testni zapiski). Ohraniti jo kot varno privzeto oziroma prehodno kategorijo, vendar občasno arhivirati zastarele zapise; MemoryDown ni upravitelj opravil.
- Ni potrebe po novi kategoriji `tasks` ali `inbox`; za uporabne nadaljnje obveznosti uporabiti oznaki `task`/`followup`, ne kot statusni sistem.
- Ne dodajati `skills` med privzete kategorije, dokler se ne nabere več takih zapisov.
- Dva zapisa v `writings` imata `public: true`. Ali ju streže produkcijski API, je odvisno od `PUBLIC_API_ENABLED`; stanje okolja prek MCP-ja ni bilo preverjeno.
- V `workflows` je en aktiviran workflow v načinu `apply`, ki dovoljuje tudi mutirajoča orodja. MemoryDown definicijo samo hrani; preveriti, da izvajalnik pred vsako mutacijo zahteva potrditev, kot določa pogodba workflowov.

## Kategorije in UI

- [ ] V UI dodati slovenske oznake/opise kategorij (notranje sluge naj ostanejo stabilne); trenutno je vmesnik angleški (`lang="en"`), vsebina pa pretežno slovenska.
- [ ] Poenotiti privzeto kategorijo za nov zapis: UI pri dejanju »New memory« izbere `notes`, MCP `remember` pa `facts`.
- [ ] Odločiti se, ali so uporabniške kategorije podprte. Koda sprejme vsak varen slug, opis MCP-orodja pa našteva le vgrajene kategorije; obstoječi `skills` je primer tega neskladja. Če so dovoljene, jih jasno dokumentirati in omogočiti njihovo ustvarjanje v UI.
- [ ] Jasno prikazati ločeni stanji arhiviranja in objave. Arhiviranje ne umakne zapisa z `public: true` iz API-ja, seznam v UI pa trenutno prikaže samo značko `archived`. Dodati opozorilo/obe znački ali določiti, da arhiviranje umakne objavo.
- [ ] Po potrebi počistiti testne zapise v `notes` in `workflows`; več starejših poskusov je že arhiviranih.

## Tehnične ugotovitve

- [ ] **P1 — Autosave lahko izgubi spremembe.** V `public_html/assets/app.js` se po uspešnem shranjevanju kot shranjeno zabeleži trenutni obrazec (`markSaved(currentPayload())`), ne payload, ki je bil poslan. Če uporabnik ureja med počasnim zahtevkom, so nove spremembe lahko napačno označene kot shranjene in čakajoče shranjevanje jih preskoči. Shraniti je treba poslani payload (pri novem zapisu mu dodati vrnjeni ID) ter dodati regresijski brskalniški test.
- [ ] **P2 — `remember` lahko prepiše celo telo.** `MemoryStore::findDuplicate()` obravnava enak naslov v isti kategoriji kot dvojnik; `MemoryTools::remember()` nato zamenja celotno vsebino. Razmisliti o strožjem ujemanju ali obveznem branju pred zamenjavo telesa.
- [ ] **P2 — potrjevanje workflowov.** Preveriti izvajalnik zunaj tega repozitorija: `apply` mora potrditi vsako mutacijo; MemoryDown tega ne uveljavlja.
- [ ] **P3 — javna diagnostika.** Neavtenticirani `/health` vrača absolutno pot `users_path` in skupno število zapisov. Odstraniti te podrobnosti ali jih omejiti na zaščiteno diagnostiko.
- [ ] **P3 — rate limiting.** `RateLimiter` ob napaki pri pisanju svojih datotek odpove odprto, zato je lahko zaščita prijave tiho izklopljena. To stanje izpostaviti v diagnostiki ali drugače obravnavati napako.

## Preverjanje

- `tests/unit-tests.php`: 401 uspešen, 0 neuspešnih.
- `tests/run-tests.php`: 527 uspešnih, 0 neuspešnih. Testi ne izvajajo UI JavaScripta, zato zgornja dirka autosave ni pokrita.
