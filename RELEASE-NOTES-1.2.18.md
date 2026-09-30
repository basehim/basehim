Fixes two warnings in Google PageSpeed Insights after the AI Agents update.

- "ai-catalog.json schema is invalid": the catalog is now in the exact format
  PageSpeed Insights checks against.
- "robots.txt is not valid — Unknown directive": the Agentmap line that
  robots.txt checkers don't recognise is no longer added. You can turn it back
  on in Settings → AI Agents if an AI service you use asks for it.
