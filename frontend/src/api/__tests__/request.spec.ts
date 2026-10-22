import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'

/**
 * 拦截器用例。
 *
 * 这里**不 mock axios 本身**，而是给真实实例换一个 adapter：
 * 拦截器是 axios 的组成部分，只有让它跑在真实实例上，才验证得到
 * 「响应体解包 / 令牌续期 / 401 清理」这些真正会出问题的分支。
 */
const mocks = vi.hoisted(() => ({
  messageError: vi.fn(),
  resetAfterLogout: vi.fn(),
}))

vi.mock('element-plus', () => ({
  ElMessage: { error: mocks.messageError, success: vi.fn(), warning: vi.fn() },
}))

vi.mock('@/locales', () => ({
  currentLocale: { value: 'zh-CN' },
  t: (key: string) => `t:${key}`,
}))

vi.mock('@/utils/progress', () => ({
  progressStart: vi.fn(),
  progressDone: vi.fn(),
}))

vi.mock('@/router', () => ({
  resetAfterLogout: mocks.resetAfterLogout,
}))

/**
 * jsdom 的 Blob 未实现 text()（生产浏览器 2020+ 都有），拦截器要靠它读 blob 错误体里的 JSON。
 * 这里用 FileReader 补一个最小实现，仅测试环境生效。
 */
if (typeof Blob !== 'undefined' && typeof FileReader !== 'undefined' && typeof Blob.prototype.text !== 'function') {
  Object.defineProperty(Blob.prototype, 'text', {
    value: function text(this: Blob): Promise<string> {
      return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result ?? ''))
        reader.onerror = () => reject(reader.error)
        reader.readAsText(this)
      })
    },
  })
}

import instance, { downloadFile, downloadFileByPost, http } from '@/api/request'
import { getToken } from '@/utils/auth'
import { resetTrace } from '@/utils/trace'

type AdapterConfig = InternalAxiosRequestConfig & { responseType?: string }

/** 用固定响应替换 adapter；返回最后一次请求的 config，便于断言请求头 */
function mockAdapter(response: Partial<AxiosResponse> & { data: unknown }, status = 200) {
  let lastConfig: AdapterConfig | undefined

  instance.defaults.adapter = (async (config: AdapterConfig) => {
    lastConfig = config
    return {
      data: response.data,
      status: response.status ?? status,
      statusText: 'OK',
      headers: response.headers ?? {},
      config,
    }
  }) as never

  return () => lastConfig
}

/** 模拟传输层失败（axios 会把这类错误交给响应拦截器的 error 分支） */
function mockAdapterFailure(status: number, data: unknown) {
  instance.defaults.adapter = (async (config: AdapterConfig) => {
    return await Promise.reject({ response: { status, data }, config })
  }) as never
}

describe('请求拦截器', () => {
  beforeEach(() => {
    localStorage.clear()
    resetTrace()
    vi.clearAllMocks()
  })

  it('自动带上 Bearer 令牌与当前语言', async () => {
    localStorage.setItem('cccms_token', 'abc123')
    const lastConfig = mockAdapter({ data: { code: 0, message: 'ok', data: null } })

    await http.get('/user')

    const headers = lastConfig()?.headers as unknown as Record<string, string>
    expect(headers.Authorization).toBe('Bearer abc123')
    expect(headers['Accept-Language']).toBe('zh-CN')
  })

  it('未登录时不带 Authorization', async () => {
    const lastConfig = mockAdapter({ data: { code: 0, message: 'ok', data: null } })

    await http.get('/config/ui')

    const headers = lastConfig()?.headers as unknown as Record<string, string>
    expect(headers.Authorization).toBeUndefined()
  })

  it('剔除空查询参数：空串不会被后端当成非法整数', async () => {
    const lastConfig = mockAdapter({ data: { code: 0, message: 'ok', data: null } })

    await http.get('/user', {
      page: 1,
      limit: 15,
      status: '',
      username: '',
      keyword: undefined,
      node_id: null,
      ids: [],
      trashed: 0,
    })

    // 0 是有效值（回收站开关），必须保留；其余空值全部不下发
    expect(lastConfig()?.params).toEqual({ page: 1, limit: 15, trashed: 0 })
  })

  it('带上操作 / 会话 ID：同一页面的并发请求共用一个 action', async () => {
    const seen: Array<Record<string, string>> = []
    instance.defaults.adapter = (async (config: AdapterConfig) => {
      seen.push({ ...(config.headers as unknown as Record<string, string>) })
      return {
        data: { code: 0, message: 'ok', data: null },
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      }
    }) as never

    await Promise.all([http.get('/config/ui'), http.get('/menu/routes')])

    expect(seen).toHaveLength(2)
    // 格式与后端 Cors::correlationId() 的校验规则一致
    expect(seen[0]['X-Action-Id']).toMatch(/^a\d{12}-[0-9a-f]{8}$/)
    expect(seen[1]['X-Action-Id']).toBe(seen[0]['X-Action-Id'])
    expect(seen[0]['X-Session-Id']).toMatch(/^s\d{12}-[0-9a-f]{8}$/)
    expect(seen[1]['X-Session-Id']).toBe(seen[0]['X-Session-Id'])
  })
})

describe('响应拦截器', () => {
  beforeEach(() => {
    localStorage.clear()
    resetTrace()
    vi.clearAllMocks()
  })

  it('code = 0 时直接解包 data', async () => {
    mockAdapter({ data: { code: 0, message: 'ok', data: { id: 7 } } })

    await expect(http.get('/user/read')).resolves.toEqual({ id: 7 })
    expect(mocks.messageError).not.toHaveBeenCalled()
  })

  it('code != 0 时弹出后端消息并 reject（业务错误也走统一提示）', async () => {
    mockAdapter({ data: { code: 422, message: '用户名已存在', data: null } })

    await expect(http.post('/user/save', {})).rejects.toThrow('用户名已存在')
    expect(mocks.messageError).toHaveBeenCalledWith('用户名已存在')
  })

  it('code != 0 且后端没给消息时回落到通用文案', async () => {
    mockAdapter({ data: { code: 500, message: '', data: null } })

    await expect(http.get('/x')).rejects.toThrow('t:common.requestFailed')
  })

  it('非信封结构（没有 code 字段）原样返回', async () => {
    mockAdapter({ data: { raw: true } })

    await expect(http.get('/x')).resolves.toEqual({ raw: true })
  })

  it('X-Refresh-Token 静默替换本机令牌（滑动续期）', async () => {
    localStorage.setItem('cccms_token', 'old-token')
    mockAdapter({
      data: { code: 0, message: 'ok', data: null },
      headers: { 'x-refresh-token': 'new-token' },
    })

    await http.get('/user')

    expect(getToken()).toBe('new-token')
  })

  it('空值的 X-Refresh-Token 不覆盖现有令牌', async () => {
    localStorage.setItem('cccms_token', 'keep-me')
    mockAdapter({
      data: { code: 0, message: 'ok', data: null },
      headers: { 'x-refresh-token': '' },
    })

    await http.get('/user')

    expect(getToken()).toBe('keep-me')
  })
})

describe('错误分支', () => {
  beforeEach(() => {
    localStorage.clear()
    resetTrace()
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('401：清掉本机令牌、提示并跳登录页（同时重置动态路由）', async () => {
    localStorage.setItem('cccms_token', 'expired')
    mockAdapterFailure(401, { message: '登录已过期' })

    await expect(http.get('/user')).rejects.toBeTruthy()

    expect(getToken()).toBe('')
    expect(mocks.messageError).toHaveBeenCalledWith('登录已过期')
    await vi.waitFor(() => {
      expect(mocks.resetAfterLogout).toHaveBeenCalled()
      expect(window.location.hash).toBe('#/login')
    })
  })

  it('401 且后端无消息时用「登录已过期」兜底文案', async () => {
    mockAdapterFailure(401, undefined)

    await expect(http.get('/user')).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('t:common.loginExpired')
  })

  it('429：把后端的限流/锁定文案原样透出（登录失败双维度的前端表现）', async () => {
    mockAdapterFailure(429, { message: '登录失败次数过多，请 300 秒后再试' })

    await expect(http.post('/auth/login', {})).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('登录失败次数过多，请 300 秒后再试')
    // 429 不是鉴权失效，不能清令牌、不能跳登录页
    expect(getToken()).toBe('')
  })

  it('5xx 且后端无消息时带上状态码', async () => {
    mockAdapterFailure(503, undefined)

    await expect(http.get('/x')).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('t:common.requestError')
  })

  it('无响应（网络不可达）时提示网络错误', async () => {
    instance.defaults.adapter = (async () => {
      return await Promise.reject(new Error('Network Error'))
    }) as never

    await expect(http.get('/x')).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('t:common.networkError')
  })

  it('报错文案带上操作 ID（用户可直接到日志页检索本次操作）', async () => {
    mockAdapterFailure(500, { message: '数据库连接失败', action_id: 'a260918143012-a1b2c3d4' })

    await expect(http.get('/x')).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('数据库连接失败t:common.actionIdSuffix')
  })

  it('后端未返回 action_id 时回落到 trace_id，提示里至少要有一个可查的 ID', async () => {
    mockAdapterFailure(502, { message: 'bad gateway', trace_id: '260918143012-abcd1234' })

    await expect(http.get('/x')).rejects.toBeTruthy()

    expect(mocks.messageError).toHaveBeenCalledWith('bad gatewayt:common.actionIdSuffix')
  })
})

describe('业务错误（HTTP 200 + code != 0）', () => {
  beforeEach(() => {
    localStorage.clear()
    resetTrace()
    vi.clearAllMocks()
  })

  it('同样把操作 ID 带进提示与抛出的错误里', async () => {
    mockAdapter({
      data: { code: 422, message: '用户名已存在', data: null, action_id: 'a260918143012-deadbeef' },
    })

    await expect(http.post('/user/save', {})).rejects.toThrow('用户名已存在t:common.actionIdSuffix')
    expect(mocks.messageError).toHaveBeenCalledWith('用户名已存在t:common.actionIdSuffix')
  })
})

describe('downloadFile', () => {
  let downloadedName = ''

  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
    downloadedName = ''

    Object.defineProperty(URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), writable: true })
    Object.defineProperty(URL, 'revokeObjectURL', { value: vi.fn(), writable: true })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      downloadedName = this.download
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('blob 响应保留完整响应体，文件名取 Content-Disposition（RFC 5987 优先）', async () => {
    mockAdapter({
      data: new Blob(['id,name']),
      headers: { 'content-disposition': "attachment; filename*=UTF-8''%E5%AF%BC%E5%87%BA.csv" },
    })

    await downloadFile('/log/export', { page: 1 })

    expect(downloadedName).toBe('导出.csv')
  })

  it('没有 filename* 时回落到普通 filename', async () => {
    mockAdapter({
      data: new Blob(['id,name']),
      headers: { 'content-disposition': 'attachment; filename="log-2026.csv"' },
    })

    await downloadFile('/log/export')

    expect(downloadedName).toBe('log-2026.csv')
  })

  it('完全没有 Content-Disposition 时用调用方给的兜底名', async () => {
    mockAdapter({ data: new Blob(['x']), headers: {} })

    await downloadFile('/log/export', undefined, 'fallback.csv')

    expect(downloadedName).toBe('fallback.csv')
  })

  it('POST blob 错误：响应体是 Blob 时解析出后端 message 再提示', async () => {
    mockAdapterFailure(422, new Blob([JSON.stringify({ code: 1, message: '所选登录账号下没有广告账户' })]))

    await expect(
      downloadFileByPost('/oceanengine/delivery_link/exportTemplate', { main_list: [], file_name: '模板' }),
    ).rejects.toBeTruthy()

    await vi.waitFor(() => {
      expect(mocks.messageError).toHaveBeenCalledWith('所选登录账号下没有广告账户')
    })
  })

  it('POST blob 错误：响应体不是合法 JSON 时提示通用错误', async () => {
    mockAdapterFailure(500, new Blob(['<html>oops</html>']))

    await expect(downloadFileByPost('/x')).rejects.toBeTruthy()

    await vi.waitFor(() => {
      expect(mocks.messageError).toHaveBeenCalledWith('t:common.requestError')
    })
  })
})
