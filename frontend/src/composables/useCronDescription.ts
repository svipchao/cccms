import { useI18n } from 'vue-i18n'
import { describeCron } from '@/utils/cron'

/**
 * Cron 表达式 → 当前语言的「执行说明」。
 *
 * 判定只做一次（`utils/cron.ts` 的 `describeCron()`），**可视化编辑器与任务列表都调这里**，
 * 因此同一条表达式在两处的说法必然一致；文案 key 也集中在 `crontab.editor.desc*` 一处。
 *
 * 用法：`const { describe } = useCronDescription()`，然后 `describe(表达式)` 取说明文案。
 */

/** 周几文案键（0 = 周日）：写成静态键，便于 i18n 静态检查 */
const WEEK_KEYS: Record<number, string> = {
  0: 'cccms.crontab.editor.weekSunday',
  1: 'cccms.crontab.editor.weekMonday',
  2: 'cccms.crontab.editor.weekTuesday',
  3: 'cccms.crontab.editor.weekWednesday',
  4: 'cccms.crontab.editor.weekThursday',
  5: 'cccms.crontab.editor.weekFriday',
  6: 'cccms.crontab.editor.weekSaturday',
}

/** 返回 `describe(表达式)`：认不出来的写法回退成「自定义表达式」 */
export function useCronDescription(): { describe: (expression: string) => string } {
  const { t } = useI18n({ useScope: 'global' })

  const describe = (expression: string): string => {
    const desc = describeCron(expression)

    switch (desc.key) {
      case 'every_second':
        return t('cccms.crontab.editor.descEverySecond')
      case 'every_n_seconds':
        return t('cccms.crontab.editor.descEveryNSeconds', { n: desc.n })
      case 'every_n_minutes':
        return t('cccms.crontab.editor.descEveryNMinutes', { n: desc.n })
      case 'every_n_hours':
        return t('cccms.crontab.editor.descEveryNHours', { n: desc.n })
      case 'hourly':
        return t('cccms.crontab.editor.descHourly', { minute: desc.minute })
      case 'daily':
        return t('cccms.crontab.editor.descDaily', { time: desc.time })
      case 'weekly':
        return t('cccms.crontab.editor.descWeekly', {
          week: t(WEEK_KEYS[desc.week] ?? 'cccms.crontab.editor.weekSunday'),
          time: desc.time,
        })
      case 'workday':
        return t('cccms.crontab.editor.descWorkday', { time: desc.time })
      case 'monthly':
        return t('cccms.crontab.editor.descMonthly', { day: desc.day, time: desc.time })
      default:
        return t('cccms.crontab.editor.descCustom')
    }
  }

  return { describe }
}
