/**
 * Sentinel Chat Platform - Word Filter Service
 * 
 * Implements word filtering and profanity masking.
 * Uses configurable filter lists and patterns.
 */

import { Injectable } from '@nestjs/common';

@Injectable()
export class WordFilterService {
  private readonly profanityPatterns = [
    // Add profanity patterns here (simplified for example)
    /\b(example|profanity|words)\b/gi,
  ];

  filterMessage(text: string): { filtered: string; flagged: boolean } {
    let filtered = text;
    let flagged = false;

    // Apply profanity filters
    for (const pattern of this.profanityPatterns) {
      if (pattern.test(text)) {
        filtered = filtered.replace(pattern, (match) => '*'.repeat(match.length));
        flagged = true;
      }
    }

    return {
      filtered,
      flagged,
    };
  }
}

