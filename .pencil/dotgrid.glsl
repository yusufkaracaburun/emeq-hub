/** @resolution */
uniform vec2 u_resolution;

/**
 * @label Cell
 * @default 34
 * @range 8, 120
 */
uniform float u_cell;

/**
 * @label Color
 * @color
 * @default #cf6bc2
 */
uniform vec3 u_color;

/**
 * @label Opacity
 * @default 0.16
 * @range 0, 1
 */
uniform float u_opacity;

/**
 * @label Fade
 * @default 0.7
 * @range 0, 1
 */
uniform float u_fade;

void main() {
  vec2 p = mod(gl_FragCoord.xy, u_cell);
  float d = length(p - vec2(u_cell * 0.5));
  float dot = 1.0 - smoothstep(0.8, 1.7, d);
  // radial falloff from top-left so the grid dissolves toward the bottom-right
  vec2 uv = gl_FragCoord.xy / u_resolution;
  float fade = mix(1.0, 1.0 - length(uv - vec2(0.15, 0.1)), u_fade);
  fade = clamp(fade, 0.0, 1.0);
  gl_FragColor = vec4(u_color, dot * u_opacity * fade);
}
