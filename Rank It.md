# Rank It

**Rank It helps you remember why you made a choice the last time you faced it.** The clearest example in the conversation is a subcontractor who comes to mind when work gets busy. You remember that they can do the job. Someone else remembers that they never supplied proof of insurance and it cost the business money. A useful app would put that experience in front of you *before* you make the call, with the original note so you can judge whether it still applies.

## What came through in the conversation

- **The ratings are personal.** You choose what matters to you. For subcontractors, the conversation mentions reliability, punctuality, and skill, plus an existing 1–5 scale and a special “do not call” designation. The recording mentions five criteria, but the full list is unclear. For a drill or a drink, entirely different criteria would make sense.
- **A score needs a reason and a date.** “Would hire again” helps build a shortlist. “Left halfway through the job” helps you decide whether to call someone for a particular assignment. Weekly ratings and one-time observations both came up, so the app should preserve a history.
- **Capture is the hard part.** Two plausible interfaces emerged. One starts with a category and a consistent set of questions. The other starts with a button you can hit when you get back in the car and the experience is fresh. The budgeting-app anecdote illustrates the desired interaction well: say what happened, answer a short follow-up if needed, and save.
- **Recall should work even when the question sounds different from the note.** “Which Waterloo flavor did I like?” is a lookup. “I’m bringing drinks to a party. What should I get?” should also find the blackberry lemonade preference. On the business side, “Who have I used in the last year and said I’d hire again?” requires dates and explicit preferences as well as text search.
- **Getting the person right matters.** The conversation describes confusing two people and recognizing a repeat caller only when an old text exchange resurfaced. Linking a note to the right contact or business may be worth more than an elaborate scoring formula.

## The first version I’d build

I’d pilot **subcontractor recall** with your brother and use the Waterloo example as a second test of the same design. The subcontractor case has a recurring decision, a cost when memory fails, and existing records he can check. The drink example keeps the design flexible enough to serve the broader idea.

Give the app two main actions: **Remember this** and **Ask my memory**.

| Action | How it should work |
|---|---|
| **Remember this** | Speak or type a note. Show the original wording and the suggested person, business, or item. If two contacts match, ask which one. Let the user add a score, a reason, or a “would hire again” or “do not call” flag. A simple flavor preference should take one tap to save. |
| **Ask my memory** | Accept a question in ordinary language. Return an answer or shortlist with a dated note behind each result. For “Who should I call?”, apply the user’s explicit flags. For “Why did I stop calling this company?”, show the reason. If the last note says insurance was missing in March, report what was recorded in March and leave its current status open. |

This accommodates both capture ideas from the conversation. Someone can speak freely after a job, then use a short scorecard during a weekly review. Either path adds to the same history.

## Build and test plan

1. **Test the decision before writing code.** Have your brother pick ten subcontractors he actually remembers using. For each, record a short note in his own words. A few days later, ask him to name a real upcoming job and use those notes to make a call list. Pay attention to what changes his choice and what he wishes he had written down. Use that to settle the first scorecard.
2. **Build the smallest complete interaction.** Start with a private, phone-friendly app, device dictation, and editable notes. Store the original note, the person or item it concerns, the experience date if known, and any ratings or flags the user confirms. Keep each observation separately so a later experience can update an earlier judgment without erasing its basis.
3. **Implement two kinds of retrieval.** Use database filters for exact conditions such as “used in the last year” and “would hire again.” Use text and meaning-based search for questions like “What drinks should I bring?” Generate answers from the retrieved entries and make each supporting note one tap away. If only four people qualify for a requested top twelve, return those four.
4. **Pilot it through real decisions.** Track how long it takes to save a note, whether the right contact and evidence surface, and whether the answer helps your brother decide. Include cases with similar names, a changed preference, and a question his notes cannot answer. Let the observed friction determine whether to add weekly prompts, phone-contact integration, or more categories first.

**The first milestone:** Between jobs, your brother can dictate one experience in under 30 seconds. Weeks later, while deciding whom to call, he can retrieve the right person, his own reason, and the date he recorded it. That single interaction tests the central promise of *Rank It*.
