<details @if(old('experience_date', $experienceDate) || $errors->has('experience_date')) open @endif>
    <summary>Experience date</summary>
    <label for="experience_date">Year, month, or day
        <input id="experience_date" name="experience_date" value="{{ old('experience_date', $experienceDate) }}"
               aria-describedby="date-help" maxlength="10">
    </label>
    <small id="date-help">Use YYYY, YYYY-MM, or YYYY-MM-DD. Leave blank if unknown.</small>
</details>
